import { readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';

export const CLA_SIGN_COMMENT = 'I have read the CLA Document and I hereby sign the CLA';

const SIGNATURE_BRANCH = 'cla-signatures';
const SIGNATURE_PATH = 'signatures/version1/cla.json';
const BOT_MARKER = '<!-- artifactflow-cla -->';
const MAX_PAGES = 50;
const MAX_CREDITED_AUTHORS = 10_000;
const ALLOWLIST = new Map([
  ['gadsotek', 14184492],
  ['dependabot[bot]', 49699333],
  ['dependabot-preview[bot]', 27856297],
  ['renovate[bot]', 29139614],
]);

const COMMITS_QUERY = `
  query($owner: String!, $repo: String!, $number: Int!, $cursor: String) {
    repository(owner: $owner, name: $repo) {
      pullRequest(number: $number) {
        commits(first: 100, after: $cursor) {
          totalCount
          edges {
            node {
              commit {
                oid
                author {
                  name
                  user {
                    databaseId
                    login
                  }
                }
                authors(first: 100) {
                  totalCount
                  nodes {
                    name
                    email
                    user {
                      databaseId
                      login
                    }
                  }
                  pageInfo {
                    hasNextPage
                  }
                }
              }
            }
          }
          pageInfo {
            hasNextPage
            endCursor
          }
        }
      }
    }
  }
`;

function requiredUser(user, label) {
  if (
    !Number.isSafeInteger(user?.id) ||
    user.id <= 0 ||
    typeof user.login !== 'string' ||
    !user.login
  ) {
    throw new Error(`GitHub did not return a trusted account for ${label}.`);
  }
  return { id: user.id, login: user.login };
}

export async function collectCommits(graphql, owner, repo, number) {
  const commits = [];
  const seenCursors = new Set();
  let cursor = null;
  let expectedCount = null;
  let creditedAuthors = 0;

  for (let page = 1; page <= MAX_PAGES; page += 1) {
    const result = await graphql(COMMITS_QUERY, { owner, repo, number, cursor });
    const connection = result?.repository?.pullRequest?.commits;
    if (
      !connection ||
      !Array.isArray(connection.edges) ||
      !Number.isSafeInteger(connection.totalCount)
    ) {
      throw new Error('GitHub returned an incomplete PR commit page.');
    }
    if (expectedCount !== null && connection.totalCount !== expectedCount) {
      throw new Error('The PR commit list changed during CLA evaluation.');
    }
    expectedCount = connection.totalCount;

    for (const edge of connection.edges) {
      const gitCommit = edge?.node?.commit;
      if (!/^[a-f0-9]{40}$/i.test(gitCommit?.oid ?? '')) {
        throw new Error('GitHub returned a PR commit without a valid SHA.');
      }
      const authors = gitCommit.authors;
      if (
        !authors ||
        !Number.isSafeInteger(authors.totalCount) ||
        !Array.isArray(authors.nodes) ||
        authors.totalCount > 100 ||
        authors.pageInfo?.hasNextPage === true
      ) {
        throw new Error(
          'A PR commit has too many credited authors for the CLA check; request maintainer review.',
        );
      }
      if (authors.totalCount < 1 || authors.nodes.length !== authors.totalCount) {
        throw new Error('GitHub returned an incomplete credited-author list.');
      }
      creditedAuthors += authors.totalCount;
      if (creditedAuthors > MAX_CREDITED_AUTHORS) {
        throw new Error('Too many credited authors across the PR; request maintainer review.');
      }
      const linked = gitCommit.author?.user;
      commits.push({
        oid: gitCommit.oid,
        author: {
          user: linked ? { id: linked.databaseId, login: linked.login } : null,
        },
        coauthors: authors.nodes.map((actor) => ({
          name: actor?.name,
          email: actor?.email,
          user: actor?.user ? { id: actor.user.databaseId, login: actor.user.login } : null,
        })),
      });
    }

    const pageInfo = connection.pageInfo;
    if (pageInfo?.hasNextPage === false) {
      if (commits.length === 0 || commits.length !== expectedCount) {
        throw new Error('GitHub did not return every PR commit.');
      }
      return commits;
    }
    if (
      pageInfo?.hasNextPage !== true ||
      !pageInfo.endCursor ||
      seenCursors.has(pageInfo.endCursor)
    ) {
      throw new Error('GitHub returned an unusable PR commit cursor.');
    }
    seenCursors.add(pageInfo.endCursor);
    cursor = pageInfo.endCursor;
  }

  throw new Error('The PR has too many commits for the CLA check; request maintainer review.');
}

export async function collectComments(request, owner, repo, number) {
  const comments = [];
  for (let page = 1; page <= MAX_PAGES; page += 1) {
    const batch = await request(
      'GET',
      `/repos/${owner}/${repo}/issues/${number}/comments?per_page=100&page=${page}`,
    );
    if (!Array.isArray(batch) || batch.length > 100) {
      throw new Error('GitHub returned an invalid PR comment page.');
    }
    comments.push(...batch);
    if (batch.length < 100) return comments;
  }
  throw new Error('The PR has too many comments for the CLA check; request maintainer review.');
}

export function evaluateCla({
  opener,
  commits,
  comments,
  signedContributors,
  allowlistedAccounts,
  repoId,
  pullRequestNo,
}) {
  const trustedOpener = requiredUser(opener, 'the PR opener');
  const required = new Map([[trustedOpener.id, trustedOpener]]);
  const unattributed = new Set();
  const openerAllowlisted =
    allowlistedAccounts.get(trustedOpener.login.toLowerCase()) === trustedOpener.id;

  for (const gitCommit of commits) {
    const linked = gitCommit?.author?.user;
    if (!linked) {
      unattributed.add(gitCommit?.oid ?? 'unknown');
    } else {
      const user = requiredUser(linked, `commit ${gitCommit.oid}`);
      required.set(user.id, user);
    }

    for (const actor of gitCommit.coauthors ?? []) {
      // This exact trailer is an AI attribution already used in repository
      // history. It is not an individual able to grant the CLA. GitHub happens
      // to email-link it to the unrelated `claude` account, so do not exempt
      // that login or account ID generally.
      if (actor?.name === 'Claude Opus 4.8' && actor.email === 'noreply@anthropic.com') continue;
      if (!actor?.user) {
        unattributed.add(gitCommit?.oid ?? 'unknown');
        continue;
      }
      const user = requiredUser(actor.user, `credited author of commit ${gitCommit.oid}`);
      required.set(user.id, user);
    }
  }

  const alreadySigned = new Set(signedContributors.map((record) => record.id));
  const newSignatures = [];
  const signingComments = new Map();
  for (const comment of comments) {
    if (
      typeof comment?.body === 'string' &&
      comment.body.trim().toLowerCase() === CLA_SIGN_COMMENT.toLowerCase() &&
      comment.user?.login !== 'github-actions[bot]' &&
      Number.isSafeInteger(comment.user?.id) &&
      required.has(comment.user.id) &&
      !signingComments.has(comment.user.id)
    ) {
      signingComments.set(comment.user.id, comment);
    }
  }

  for (const [id, user] of required) {
    // Git metadata can impersonate any email-linked account. An exemption applies
    // only when that account is also the authenticated PR opener.
    if (id === trustedOpener.id && openerAllowlisted) continue;
    if (alreadySigned.has(id)) continue;
    const comment = signingComments.get(id);
    if (!comment) continue;
    if (!Number.isSafeInteger(comment.id) || typeof comment.created_at !== 'string') {
      throw new Error('GitHub returned an invalid CLA signature comment.');
    }
    newSignatures.push({
      name: user.login,
      id,
      comment_id: comment.id,
      created_at: comment.created_at,
      repoId,
      pullRequestNo,
    });
  }

  const signedIds = new Set([...alreadySigned, ...newSignatures.map((record) => record.id)]);
  const unsigned = [...required.values()].filter(
    (user) => !(user.id === trustedOpener.id && openerAllowlisted) && !signedIds.has(user.id),
  );

  return {
    passed: unsigned.length === 0 && unattributed.size === 0,
    unsigned,
    unattributed: [...unattributed],
    newSignatures,
  };
}

class GitHubApiError extends Error {
  constructor(status, method, path) {
    super(`GitHub API ${method} ${path} failed with HTTP ${status}.`);
    this.status = status;
  }
}

function createClient(token) {
  const headers = {
    Accept: 'application/vnd.github+json',
    Authorization: `Bearer ${token}`,
    'X-GitHub-Api-Version': '2022-11-28',
  };

  async function request(method, path, body) {
    const response = await fetch(new URL(path, 'https://api.github.com'), {
      method,
      headers: body === undefined ? headers : { ...headers, 'Content-Type': 'application/json' },
      body: body === undefined ? undefined : JSON.stringify(body),
      signal: AbortSignal.timeout(10_000),
    });
    if (!response.ok) throw new GitHubApiError(response.status, method, path);
    if (response.status === 204) return null;
    return response.json();
  }

  async function graphql(query, variables) {
    const result = await request('POST', '/graphql', { query, variables });
    if (result?.errors?.length) throw new Error('GitHub GraphQL could not list the PR commits.');
    return result?.data;
  }

  return { request, graphql };
}

function decodeSignatures(content) {
  if (typeof content !== 'string') throw new Error('The CLA signatures file has no content.');
  const parsed = JSON.parse(Buffer.from(content.replace(/\s/g, ''), 'base64').toString('utf8'));
  if (
    !parsed ||
    !Array.isArray(parsed.signedContributors) ||
    parsed.signedContributors.some(
      (record) =>
        !Number.isSafeInteger(record?.id) || record.id <= 0 || typeof record.name !== 'string',
    )
  ) {
    throw new Error('The CLA signatures file has an invalid schema.');
  }
  return parsed;
}

async function readSignatures(request, owner, repo) {
  const path = `/repos/${owner}/${repo}/contents/${SIGNATURE_PATH}?ref=${SIGNATURE_BRANCH}`;
  try {
    const file = await request('GET', path);
    if (file?.type !== 'file' || typeof file.sha !== 'string') {
      throw new Error('The CLA signatures path is not a file.');
    }
    return { sha: file.sha, data: decodeSignatures(file.content) };
  } catch (error) {
    if (error.status === 404) return { sha: null, data: { signedContributors: [] } };
    throw error;
  }
}

async function saveSignatures(request, owner, repo, records) {
  if (records.length === 0) return;
  const path = `/repos/${owner}/${repo}/contents/${SIGNATURE_PATH}`;
  for (let attempt = 0; attempt < 4; attempt += 1) {
    const current = await readSignatures(request, owner, repo);
    const existing = new Set(current.data.signedContributors.map((record) => record.id));
    const additions = records.filter((record) => !existing.has(record.id));
    if (additions.length === 0) return;
    const content = Buffer.from(
      JSON.stringify(
        {
          ...current.data,
          signedContributors: [...current.data.signedContributors, ...additions],
        },
        null,
        2,
      ),
    ).toString('base64');
    try {
      await request('PUT', path, {
        message: `Record CLA signatures for ${owner}/${repo}`,
        content,
        branch: SIGNATURE_BRANCH,
        ...(current.sha ? { sha: current.sha } : {}),
      });
      return;
    } catch (error) {
      if (error.status !== 409 || attempt === 3) throw error;
    }
  }
}

function botComment(comments) {
  return comments.find(
    (comment) =>
      comment.user?.login === 'github-actions[bot]' &&
      (comment.body?.includes(BOT_MARKER) || /CLA Assistant Lite bot/i.test(comment.body ?? '')),
  );
}

export function statusComment(result) {
  if (result.passed) {
    return `All contributors have signed the CLA.\n\n${BOT_MARKER}`;
  }
  const lines = [
    'Thank you for the contribution. Before it can be reviewed and merged,',
    'please read the [Contributor License Agreement](https://github.com/Gadsotek/artifactflow/blob/main/CLA.md)',
    'and sign it by posting this exact comment:',
    '',
    CLA_SIGN_COMMENT,
  ];
  if (result.unsigned.length) {
    const examples = result.unsigned.slice(0, 10).map((user) => `@${user.login}`);
    const remaining = result.unsigned.length - examples.length;
    lines.push(
      '',
      `Awaiting CLA from: ${examples.join(', ')}${remaining ? `, and ${remaining} more` : ''}`,
    );
  }
  if (result.unattributed.length) {
    const examples = result.unattributed.slice(0, 10).map((sha) => sha.slice(0, 12));
    const remaining = result.unattributed.length - examples.length;
    lines.push(
      '',
      `Unlinked commit authors: ${examples.join(', ')}${remaining ? `, and ${remaining} more` : ''}.`,
      'Associate each commit author email with its GitHub account and update the PR, or ask a maintainer to review the attribution. The check remains blocked until then.',
    );
  }
  lines.push('', BOT_MARKER);
  return lines.join('\n');
}

async function updateBotComment(request, owner, repo, number, comments, result) {
  const existing = botComment(comments);
  if (!existing && result.passed) return;
  const body = statusComment(result);
  if (existing?.body === body) return;
  if (existing) {
    await request('PATCH', `/repos/${owner}/${repo}/issues/comments/${existing.id}`, { body });
  } else {
    await request('POST', `/repos/${owner}/${repo}/issues/${number}/comments`, { body });
  }
}

async function latestPrCheckRun(request, owner, repo, headSha, number) {
  const candidates = [];
  for (let page = 1; page <= MAX_PAGES; page += 1) {
    const query = new URLSearchParams({
      event: 'pull_request_target',
      head_sha: headSha,
      per_page: '100',
      page: String(page),
    });
    const result = await request(
      'GET',
      `/repos/${owner}/${repo}/actions/workflows/cla.yml/runs?${query}`,
    );
    const runs = result?.workflow_runs;
    if (!Array.isArray(runs) || runs.length > 100)
      throw new Error('GitHub did not return valid CLA workflow runs.');
    candidates.push(
      ...runs.filter(
        (run) =>
          run.head_sha === headSha &&
          run.event === 'pull_request_target' &&
          run.display_title === `CLA PR #${number}`,
      ),
    );
    if (runs.length < 100) break;
    if (page === MAX_PAGES) throw new Error('Too many CLA workflow runs to identify the PR check.');
  }
  if (candidates.length === 0) {
    return null;
  }
  if (
    candidates.some((run) => !Number.isSafeInteger(run.run_number) || !Number.isSafeInteger(run.id))
  ) {
    throw new Error('GitHub returned a CLA run without a stable identity.');
  }
  candidates.sort((left, right) => right.run_number - left.run_number);
  if (candidates.length > 1 && candidates[0].run_number === candidates[1].run_number) {
    throw new Error('GitHub returned ambiguous CLA runs for this PR head.');
  }
  return candidates[0];
}

async function assertTrustedClaRun(request, owner, repo, runId) {
  const result = await request(
    'GET',
    `/repos/${owner}/${repo}/actions/runs/${runId}/jobs?per_page=100`,
  );
  const jobs = result?.jobs;
  const steps = jobs?.[0]?.steps?.map((step) => step.name);
  if (
    result?.total_count !== 1 ||
    !Array.isArray(jobs) ||
    jobs.length !== 1 ||
    jobs[0].name !== 'cla' ||
    !Array.isArray(steps) ||
    !steps.includes('Checkout trusted base commit') ||
    !steps.includes('Test CLA policy') ||
    !steps.includes('Check and record CLA')
  ) {
    throw new Error(
      'The matching CLA run is legacy or incomplete. Update the PR branch to trigger a fresh check.',
    );
  }
}

export async function rerunFailedCheck(
  request,
  owner,
  repo,
  headSha,
  number,
  {
    maxAttempts = 61,
    sleep = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds)),
  } = {},
) {
  if (!Number.isSafeInteger(maxAttempts) || maxAttempts < 1 || maxAttempts > 61) {
    throw new Error('The CLA run poll limit is invalid.');
  }
  let sawMatchingRun = false;
  for (let attempt = 1; attempt <= maxAttempts; attempt += 1) {
    const latest = await latestPrCheckRun(request, owner, repo, headSha, number);
    if (latest) sawMatchingRun = true;
    if (latest?.conclusion === 'failure') {
      await assertTrustedClaRun(request, owner, repo, latest.id);
      await request('POST', `/repos/${owner}/${repo}/actions/runs/${latest.id}/rerun`);
      return;
    }
    if (latest?.conclusion === 'success') {
      await assertTrustedClaRun(request, owner, repo, latest.id);
      return;
    }
    if (latest && latest.status === 'completed') {
      throw new Error('The latest CLA run needs maintainer review before it can be refreshed.');
    }
    if (
      latest &&
      !['queued', 'in_progress', 'waiting', 'pending', 'requested'].includes(latest.status)
    ) {
      throw new Error('GitHub returned an unknown CLA run status.');
    }
    if (attempt < maxAttempts) await sleep(5_000);
  }
  if (!sawMatchingRun) {
    throw new Error(
      'No PR-specific CLA run exists for this head after bounded polling. Update the PR branch to trigger a fresh check.',
    );
  }
  throw new Error(
    'The PR-specific CLA run is still pending; polling timed out. Comment recheck after the PR check finishes.',
  );
}

export async function runCla(event, eventName, client) {
  const repository = event?.repository;
  const owner = repository?.owner?.login;
  const repo = repository?.name;
  const number = eventName === 'issue_comment' ? event?.issue?.number : event?.pull_request?.number;
  if (!owner || !repo || !Number.isSafeInteger(number) || number <= 0) {
    throw new Error('The GitHub event does not identify a PR in this repository.');
  }
  if (!Number.isSafeInteger(repository.id) || repository.id <= 0) {
    throw new Error('The GitHub event does not identify a repository ID.');
  }
  if (eventName === 'pull_request_target' && event.action === 'closed') return;
  if (eventName === 'issue_comment') {
    const body = event?.comment?.body?.trim();
    if (
      event.action !== 'created' ||
      !event.issue?.pull_request ||
      event.comment?.user?.type === 'Bot' ||
      !Number.isSafeInteger(event.comment?.id) ||
      !Number.isSafeInteger(event.comment?.user?.id) ||
      (body?.toLowerCase() !== CLA_SIGN_COMMENT.toLowerCase() && body !== 'recheck')
    ) {
      throw new Error('The issue comment event is not an eligible PR signature or recheck.');
    }
  } else if (eventName !== 'pull_request_target') {
    throw new Error('The CLA workflow received an unsupported event.');
  }

  const { request, graphql } = client;
  const path = `/repos/${owner}/${repo}/pulls/${number}`;
  const pullRequest = await request('GET', path);
  if (pullRequest?.state !== 'open') return;
  const opener = requiredUser(pullRequest.user, 'the PR opener');
  const headSha = pullRequest.head?.sha;
  if (!/^[a-f0-9]{40}$/i.test(headSha ?? '')) throw new Error('The PR has no valid head SHA.');

  const commits = await collectCommits(graphql, owner, repo, number);
  const comments = await collectComments(request, owner, repo, number);
  if (eventName === 'issue_comment') {
    const eventComment = await request(
      'GET',
      `/repos/${owner}/${repo}/issues/comments/${event.comment.id}`,
    );
    const currentBody = eventComment?.body?.trim();
    if (
      eventComment?.id !== event.comment.id ||
      eventComment.user?.id !== event.comment.user.id ||
      (currentBody?.toLowerCase() !== CLA_SIGN_COMMENT.toLowerCase() && currentBody !== 'recheck')
    ) {
      throw new Error('The triggering PR comment changed or disappeared before CLA evaluation.');
    }
    if (!comments.some((comment) => comment.id === eventComment.id)) comments.push(eventComment);
  }
  const signatures = await readSignatures(request, owner, repo);
  const claInputs = {
    opener,
    commits,
    comments,
    allowlistedAccounts: ALLOWLIST,
    repoId: repository.id,
    pullRequestNo: number,
  };
  const result = evaluateCla({
    ...claInputs,
    signedContributors: signatures.data.signedContributors,
  });

  const currentPullRequest = await request('GET', path);
  if (currentPullRequest?.head?.sha !== headSha) {
    throw new Error('The PR head changed during CLA evaluation.');
  }

  await saveSignatures(request, owner, repo, result.newSignatures);
  const durable = await readSignatures(request, owner, repo);
  const settled = evaluateCla({
    ...claInputs,
    signedContributors: durable.data.signedContributors,
  });
  if (settled.newSignatures.length) {
    throw new Error('A CLA signature was not visible in durable storage after the update.');
  }
  const finalPullRequest = await request('GET', path);
  if (finalPullRequest?.head?.sha !== headSha) {
    throw new Error('The PR head changed after CLA signatures were recorded.');
  }
  await updateBotComment(request, owner, repo, number, comments, settled);

  if (!settled.passed) {
    throw new Error(
      `CLA pending for ${settled.unsigned.length} account(s) and ${settled.unattributed.length} unlinked commit(s).`,
    );
  }
  if (eventName === 'issue_comment') {
    await rerunFailedCheck(request, owner, repo, headSha, number);
  }
}

if (process.argv[1] && fileURLToPath(import.meta.url) === process.argv[1]) {
  const token = process.env.GITHUB_TOKEN;
  const eventPath = process.env.GITHUB_EVENT_PATH;
  const eventName = process.env.GITHUB_EVENT_NAME;
  if (!token || !eventPath || !eventName) {
    throw new Error('The CLA workflow is missing its GitHub token or event context.');
  }
  const event = JSON.parse(await readFile(eventPath, 'utf8'));
  await runCla(event, eventName, createClient(token));
}
