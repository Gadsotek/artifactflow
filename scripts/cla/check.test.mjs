import assert from 'node:assert/strict';
import test from 'node:test';

import {
  CLA_SIGN_COMMENT,
  collectCommits,
  collectComments,
  evaluateCla,
  rerunFailedCheck,
  runCla,
  statusComment,
} from './check.mjs';

const owner = { id: 14184492, login: 'Gadsotek' };
const contributor = { id: 12345, login: 'new-contributor' };
const trustedJobs = () => ({
  total_count: 1,
  jobs: [
    {
      name: 'cla',
      steps: [
        { name: 'Checkout trusted base commit' },
        { name: 'Test CLA policy' },
        { name: 'Check and record CLA' },
      ],
    },
  ],
});

function commit(oid, user, name = user?.login ?? 'Gadsotek') {
  const author = { name, user: user ? { ...user, databaseId: user.id } : null };
  return {
    oid,
    author,
    authors: { totalCount: 1, nodes: [author], pageInfo: { hasNextPage: false } },
    coauthors: [author],
  };
}

function evaluate({ opener = contributor, commits, comments = [], signedContributors = [] }) {
  return evaluateCla({
    opener,
    commits,
    comments,
    signedContributors,
    allowlistedAccounts: new Map([
      ['gadsotek', owner.id],
      ['dependabot[bot]', 49699333],
    ]),
    repoId: 456,
    pullRequestNo: 12,
  });
}

test('a forged raw maintainer name cannot bypass the CLA', () => {
  const result = evaluate({ commits: [commit('a'.repeat(40), null)] });

  assert.equal(result.passed, false);
  assert.deepEqual(
    result.unsigned.map((user) => user.login),
    ['new-contributor'],
  );
  assert.deepEqual(result.unattributed, ['a'.repeat(40)]);
});

test('a forged email-linked maintainer identity cannot exempt a different PR opener', () => {
  const result = evaluate({ commits: [commit('b'.repeat(40), owner)] });

  assert.equal(result.passed, false);
  assert.deepEqual(
    result.unsigned.map((user) => user.login),
    ['new-contributor', 'Gadsotek'],
  );
});

test('an authenticated allowlisted opener and existing signed records retain their privileges', () => {
  assert.equal(evaluate({ opener: owner, commits: [commit('c'.repeat(40), owner)] }).passed, true);

  const signed = evaluate({
    commits: [commit('d'.repeat(40), contributor)],
    signedContributors: [{ id: contributor.id, name: contributor.login, comment_id: 99 }],
  });
  assert.equal(signed.passed, true);
});

test('a reused allowlisted login or a renamed account cannot inherit the exemption', () => {
  const reusedLogin = { id: 999, login: 'Gadsotek' };
  const renamedOwner = { id: owner.id, login: 'renamed-owner' };

  for (const opener of [reusedLogin, renamedOwner]) {
    const result = evaluate({ opener, commits: [commit('a'.repeat(40), opener)] });
    assert.equal(result.passed, false);
    assert.deepEqual(
      result.unsigned.map((user) => user.id),
      [opener.id],
    );
  }
});

test('credited linked human coauthors must sign, while the exact AI attribution trailer is exempt', () => {
  const human = { id: 777, login: 'human-coauthor' };
  const authored = commit('c'.repeat(40), owner);
  authored.coauthors.push({ name: human.login, email: 'human@example.org', user: human });

  const unsigned = evaluate({ opener: owner, commits: [authored] });
  assert.equal(unsigned.passed, false);
  assert.deepEqual(
    unsigned.unsigned.map((user) => user.id),
    [human.id],
  );

  const signed = evaluate({
    opener: owner,
    commits: [authored],
    comments: [{ id: 90, body: CLA_SIGN_COMMENT, user: human, created_at: '2026-11-08T12:00:00Z' }],
  });
  assert.equal(signed.passed, true);

  const ai = commit('d'.repeat(40), owner);
  ai.coauthors.push({
    name: 'Claude Opus 4.8',
    email: 'noreply@anthropic.com',
    user: { id: 81847, login: 'claude' },
  });
  assert.equal(evaluate({ opener: owner, commits: [ai] }).passed, true);

  ai.coauthors[1].email = 'other@example.org';
  assert.equal(evaluate({ opener: owner, commits: [ai] }).passed, false);
});

test('unlinked human coauthor attribution blocks the CLA with a clear commit reference', () => {
  const authored = commit('e'.repeat(40), owner);
  authored.coauthors.push({ name: 'Unknown Human', email: 'unknown@example.org', user: null });
  const result = evaluate({ opener: owner, commits: [authored] });

  assert.equal(result.passed, false);
  assert.deepEqual(result.unattributed, ['e'.repeat(40)]);
});

test('coauthor pagination beyond 100 fails closed', async () => {
  const authored = commit('f'.repeat(40), owner);
  authored.authors = {
    totalCount: 101,
    nodes: Array.from({ length: 100 }, () => authored.author),
    pageInfo: { hasNextPage: true },
  };
  const graphql = async () => ({
    repository: {
      pullRequest: {
        commits: {
          totalCount: 1,
          edges: [{ node: { commit: authored } }],
          pageInfo: { hasNextPage: false },
        },
      },
    },
  });

  await assert.rejects(
    collectCommits(graphql, 'Gadsotek', 'artifactflow', 12),
    /too many credited authors/,
  );
});

test('aggregate credited authors across a PR have a bounded limit', async () => {
  const firstPage = Array.from({ length: 100 }, (_, index) => {
    const authored = commit(index.toString(16).padStart(40, '0'), owner);
    authored.authors = {
      totalCount: 100,
      nodes: Array.from({ length: 100 }, () => authored.author),
      pageInfo: { hasNextPage: false },
    };
    return { node: { commit: authored } };
  });
  const last = { node: { commit: commit('f'.repeat(40), owner) } };
  const graphql = async (_query, variables) => ({
    repository: {
      pullRequest: {
        commits: {
          totalCount: 101,
          edges: variables.cursor ? [last] : firstPage,
          pageInfo: { hasNextPage: !variables.cursor, endCursor: 'next' },
        },
      },
    },
  });

  await assert.rejects(
    collectCommits(graphql, 'Gadsotek', 'artifactflow', 12),
    /Too many credited authors across the PR/,
  );
});

test('the 101st PR commit is included in CLA evaluation', async () => {
  const cursors = [];
  const firstPage = Array.from({ length: 100 }, (_, index) => ({
    node: { commit: commit(String(index).padStart(40, '0'), owner) },
  }));
  const graphql = async (_query, variables) => {
    cursors.push(variables.cursor);
    const first = variables.cursor === null;
    return {
      repository: {
        pullRequest: {
          commits: {
            totalCount: 101,
            edges: first ? firstPage : [{ node: { commit: commit('f'.repeat(40), contributor) } }],
            pageInfo: { hasNextPage: first, endCursor: first ? 'page-1' : 'page-2' },
          },
        },
      },
    };
  };

  const commits = await collectCommits(graphql, 'Gadsotek', 'artifactflow', 12);

  assert.equal(commits.length, 101);
  assert.deepEqual(cursors, [null, 'page-1']);
  const result = evaluate({ opener: owner, commits });
  assert.equal(result.passed, false);
  assert.deepEqual(
    result.unsigned.map((user) => user.login),
    ['new-contributor'],
  );
});

test('a signature comment after the first 100 comments is accepted and recorded', async () => {
  const pages = [];
  const filler = Array.from({ length: 100 }, (_, index) => ({
    id: index + 1,
    body: 'Discussion',
    user: owner,
  }));
  const signature = {
    id: 101,
    body: `  ${CLA_SIGN_COMMENT.toUpperCase()}  `,
    created_at: '2026-11-08T12:00:00Z',
    user: contributor,
  };
  const request = async (_method, path) => {
    const page = Number(new URL(path, 'https://api.github.com').searchParams.get('page'));
    pages.push(page);
    return page === 1 ? filler : [signature];
  };

  const comments = await collectComments(request, 'Gadsotek', 'artifactflow', 12);
  const result = evaluate({ commits: [commit('e'.repeat(40), contributor)], comments });

  assert.deepEqual(pages, [1, 2]);
  assert.equal(comments.length, 101);
  assert.equal(result.passed, true);
  assert.deepEqual(result.newSignatures, [
    {
      name: contributor.login,
      id: contributor.id,
      comment_id: signature.id,
      created_at: signature.created_at,
      repoId: 456,
      pullRequestNo: 12,
    },
  ]);
});

test('oversized PR commit and comment histories fail closed after bounded pagination', async () => {
  let commitPages = 0;
  const graphql = async () => {
    commitPages += 1;
    return {
      repository: {
        pullRequest: {
          commits: {
            totalCount: 5_001,
            edges: Array.from({ length: 100 }, (_, index) => ({
              node: {
                commit: commit((commitPages * 100 + index).toString(16).padStart(40, '0'), owner),
              },
            })),
            pageInfo: { hasNextPage: true, endCursor: `page-${commitPages}` },
          },
        },
      },
    };
  };
  await assert.rejects(collectCommits(graphql, 'Gadsotek', 'artifactflow', 12), /too many commits/);
  assert.equal(commitPages, 50);

  let commentPages = 0;
  const request = async () => {
    commentPages += 1;
    return Array.from({ length: 100 }, (_, index) => ({ id: commentPages * 100 + index }));
  };
  await assert.rejects(
    collectComments(request, 'Gadsotek', 'artifactflow', 12),
    /too many comments/,
  );
  assert.equal(commentPages, 50);
});

test('CLA prompt bounds displayed contributor and commit examples', () => {
  const body = statusComment({
    passed: false,
    unsigned: Array.from({ length: 20 }, (_, index) => ({ login: `user${index}` })),
    unattributed: Array.from({ length: 20 }, (_, index) => index.toString(16).padStart(40, '0')),
  });

  assert.match(body, /@user9, and 10 more/);
  assert.doesNotMatch(body, /@user10/);
  assert.match(body, /Unlinked commit authors: .*and 10 more/);
  assert.ok(body.length < 2_000);
});

test('another account cannot sign for the PR opener', () => {
  const result = evaluate({
    commits: [commit('f'.repeat(40), contributor)],
    comments: [{ id: 1, body: CLA_SIGN_COMMENT, user: owner }],
  });

  assert.equal(result.passed, false);
  assert.deepEqual(result.newSignatures, []);
});

test('issue_comment durably merges legacy records before refreshing the PR head check', async () => {
  const headSha = '9'.repeat(40);
  const previous = {
    name: 'prior-signer',
    id: 777,
    comment_id: 78,
    created_at: '2026-11-07T12:00:00Z',
    repoId: 456,
    pullRequestNo: 4,
  };
  let persisted = [previous];
  const calls = [];
  const event = {
    repository: { id: 456, name: 'artifactflow', owner: { login: 'Gadsotek' } },
    action: 'created',
    issue: {
      number: 12,
      pull_request: { url: 'https://api.github.com/repos/Gadsotek/artifactflow/pulls/12' },
    },
    comment: { id: 101, body: CLA_SIGN_COMMENT, user: { ...contributor, type: 'User' } },
  };
  const graphql = async () => ({
    repository: {
      pullRequest: {
        commits: {
          totalCount: 2,
          edges: [
            { node: { commit: commit('a'.repeat(40), contributor) } },
            { node: { commit: commit('b'.repeat(40), { id: 777, login: 'prior-signer' }) } },
          ],
          pageInfo: { hasNextPage: false, endCursor: 'end' },
        },
      },
    },
  });
  const request = async (method, path, body) => {
    calls.push(`${method} ${path}`);
    if (method === 'GET' && path === '/repos/Gadsotek/artifactflow/pulls/12') {
      return { state: 'open', user: contributor, head: { sha: headSha } };
    }
    if (method === 'GET' && path.startsWith('/repos/Gadsotek/artifactflow/issues/12/comments?')) {
      // A deleted earlier comment can shift pages while they are scanned.
      return [];
    }
    if (method === 'GET' && path === '/repos/Gadsotek/artifactflow/issues/comments/101') {
      return {
        id: 101,
        body: CLA_SIGN_COMMENT,
        user: contributor,
        created_at: '2026-11-08T12:00:00Z',
      };
    }
    if (method === 'GET' && path.startsWith('/repos/Gadsotek/artifactflow/contents/')) {
      return {
        type: 'file',
        sha: 'existing-sha',
        content: Buffer.from(JSON.stringify({ signedContributors: persisted })).toString('base64'),
      };
    }
    if (
      method === 'PUT' &&
      path === '/repos/Gadsotek/artifactflow/contents/signatures/version1/cla.json'
    ) {
      assert.equal(body.sha, 'existing-sha');
      assert.equal(body.branch, 'cla-signatures');
      persisted = JSON.parse(Buffer.from(body.content, 'base64').toString()).signedContributors;
      return { content: { sha: 'updated-sha' } };
    }
    if (
      method === 'GET' &&
      path.startsWith('/repos/Gadsotek/artifactflow/actions/workflows/cla.yml/runs?')
    ) {
      assert.equal(
        persisted.length,
        2,
        'signature must be durable before the required check is refreshed',
      );
      return {
        workflow_runs: [
          {
            id: 9001,
            head_sha: headSha,
            event: 'pull_request_target',
            display_title: 'CLA PR #12',
            run_number: 7,
            conclusion: 'failure',
          },
        ],
      };
    }
    if (method === 'POST' && path === '/repos/Gadsotek/artifactflow/actions/runs/9001/rerun') {
      return null;
    }
    if (method === 'GET' && path.endsWith('/actions/runs/9001/jobs?per_page=100')) {
      return trustedJobs();
    }
    throw new Error(`Unexpected API call: ${method} ${path}`);
  };

  await runCla(event, 'issue_comment', { request, graphql });

  assert.deepEqual(persisted[0], previous);
  assert.equal(persisted[1].id, contributor.id);
  assert.ok(calls.includes('POST /repos/Gadsotek/artifactflow/actions/runs/9001/rerun'));
});

test('concurrent signatures converge on a passing check after durable CAS merge', async () => {
  const secondSigner = { id: 777, login: 'second-signer' };
  const headSha = '9'.repeat(40);
  let stored = [];
  let blobSha = 'initial';
  let firstReads = 0;
  let releaseReads;
  const bothRead = new Promise((resolve) => {
    releaseReads = resolve;
  });
  let reruns = 0;
  const graphql = async () => ({
    repository: {
      pullRequest: {
        commits: {
          totalCount: 2,
          edges: [
            { node: { commit: commit('a'.repeat(40), contributor) } },
            { node: { commit: commit('b'.repeat(40), secondSigner) } },
          ],
          pageInfo: { hasNextPage: false },
        },
      },
    },
  });
  const eventFor = (signer, commentId) => ({
    repository: { id: 456, name: 'artifactflow', owner: { login: 'Gadsotek' } },
    action: 'created',
    issue: { number: 12, pull_request: {} },
    comment: { id: commentId, body: CLA_SIGN_COMMENT, user: { ...signer, type: 'User' } },
  });
  const clientFor = (signer, commentId) => {
    let initialRead = true;
    const comment = {
      id: commentId,
      body: CLA_SIGN_COMMENT,
      user: signer,
      created_at: '2026-11-08T12:00:00Z',
    };
    const signatureFile = () => ({
      type: 'file',
      sha: blobSha,
      content: Buffer.from(JSON.stringify({ signedContributors: stored })).toString('base64'),
    });
    const request = async (method, path, body) => {
      if (method === 'GET' && path.endsWith('/pulls/12')) {
        return { state: 'open', user: contributor, head: { sha: headSha } };
      }
      if (method === 'GET' && path.includes('/issues/12/comments?')) return [comment];
      if (method === 'GET' && path.endsWith(`/issues/comments/${commentId}`)) return comment;
      if (method === 'GET' && path.includes('/contents/signatures/version1/cla.json')) {
        if (initialRead) {
          initialRead = false;
          const snapshot = signatureFile();
          firstReads += 1;
          if (firstReads === 2) releaseReads();
          await bothRead;
          return snapshot;
        }
        return signatureFile();
      }
      if (method === 'PUT' && path.endsWith('/contents/signatures/version1/cla.json')) {
        if (body.sha !== blobSha) {
          const conflict = new Error('conflict');
          conflict.status = 409;
          throw conflict;
        }
        stored = JSON.parse(Buffer.from(body.content, 'base64').toString()).signedContributors;
        blobSha = `updated-${stored.length}`;
        return { content: { sha: blobSha } };
      }
      if (method === 'POST' && path.endsWith('/issues/12/comments')) return { id: 999 };
      if (method === 'GET' && path.includes('/actions/workflows/cla.yml/runs?')) {
        return {
          workflow_runs: [
            {
              id: 9001,
              head_sha: headSha,
              event: 'pull_request_target',
              display_title: 'CLA PR #12',
              run_number: 7,
              conclusion: 'failure',
            },
          ],
        };
      }
      if (method === 'GET' && path.endsWith('/actions/runs/9001/jobs?per_page=100')) {
        return trustedJobs();
      }
      if (method === 'POST' && path.endsWith('/actions/runs/9001/rerun')) {
        reruns += 1;
        return null;
      }
      throw new Error(`Unexpected API call: ${method} ${path}`);
    };
    return { request, graphql };
  };

  const outcomes = await Promise.allSettled([
    runCla(eventFor(contributor, 101), 'issue_comment', clientFor(contributor, 101)),
    runCla(eventFor(secondSigner, 102), 'issue_comment', clientFor(secondSigner, 102)),
  ]);

  assert.deepEqual(
    new Set(stored.map((record) => record.id)),
    new Set([contributor.id, secondSigner.id]),
  );
  assert.ok(outcomes.some((outcome) => outcome.status === 'fulfilled'));
  assert.ok(reruns >= 1);
});

test('a missing or ambiguous PR-specific check run fails closed', async () => {
  const headSha = '9'.repeat(40);
  const baseRun = {
    id: 9001,
    head_sha: headSha,
    event: 'pull_request_target',
    display_title: 'CLA PR #12',
    run_number: 7,
    conclusion: 'failure',
  };
  const request = async (_method, _path) => ({
    workflow_runs: [{ ...baseRun, display_title: 'CLA PR #13' }],
  });

  await assert.rejects(
    rerunFailedCheck(request, 'Gadsotek', 'artifactflow', headSha, 12, { maxAttempts: 1 }),
    /No PR-specific CLA run/,
  );

  const ambiguous = async () => ({ workflow_runs: [baseRun, { ...baseRun, id: 9002 }] });
  await assert.rejects(
    rerunFailedCheck(ambiguous, 'Gadsotek', 'artifactflow', headSha, 12, { maxAttempts: 1 }),
    /ambiguous CLA runs/,
  );
});

test('a legacy CLA run with a spoofed matching PR title is never rerun', async () => {
  const headSha = '9'.repeat(40);
  let reruns = 0;
  const request = async (method, path) => {
    if (method === 'GET' && path.includes('/actions/workflows/cla.yml/runs?')) {
      return {
        workflow_runs: [
          {
            id: 9001,
            head_sha: headSha,
            event: 'pull_request_target',
            display_title: 'CLA PR #12',
            run_number: 7,
            conclusion: 'failure',
          },
        ],
      };
    }
    if (method === 'GET' && path.endsWith('/actions/runs/9001/jobs?per_page=100')) {
      return { total_count: 1, jobs: [{ name: 'cla', steps: [{ name: 'CLA Assistant Lite' }] }] };
    }
    if (method === 'POST') {
      reruns += 1;
      return null;
    }
    throw new Error(`Unexpected API call: ${method} ${path}`);
  };

  await assert.rejects(
    rerunFailedCheck(request, 'Gadsotek', 'artifactflow', headSha, 12, { maxAttempts: 1 }),
    /legacy or incomplete/,
  );
  assert.equal(reruns, 0);
});

test('a pending PR check is rerun after it fails', async () => {
  const headSha = '9'.repeat(40);
  let polls = 0;
  let sleeps = 0;
  let reruns = 0;
  const request = async (method, path) => {
    if (method === 'POST' && path.endsWith('/runs/9001/rerun')) {
      reruns += 1;
      return null;
    }
    if (method === 'GET' && path.endsWith('/runs/9001/jobs?per_page=100')) {
      return trustedJobs();
    }
    polls += 1;
    return {
      workflow_runs: [
        {
          id: 9001,
          head_sha: headSha,
          event: 'pull_request_target',
          display_title: 'CLA PR #12',
          run_number: 7,
          status: polls === 1 ? 'in_progress' : 'completed',
          conclusion: polls === 1 ? null : 'failure',
        },
      ],
    };
  };

  await rerunFailedCheck(request, 'Gadsotek', 'artifactflow', headSha, 12, {
    maxAttempts: 3,
    sleep: async () => {
      sleeps += 1;
    },
  });

  assert.equal(polls, 2);
  assert.equal(sleeps, 1);
  assert.equal(reruns, 1);
});

test('a pending PR check that succeeds is not rerun', async () => {
  const headSha = '9'.repeat(40);
  let polls = 0;
  let reruns = 0;
  const request = async (method, path) => {
    if (method === 'POST') {
      reruns += 1;
      return null;
    }
    if (method === 'GET' && path.endsWith('/runs/9001/jobs?per_page=100')) {
      return trustedJobs();
    }
    polls += 1;
    return {
      workflow_runs: [
        {
          id: 9001,
          head_sha: headSha,
          event: 'pull_request_target',
          display_title: 'CLA PR #12',
          run_number: 7,
          status: polls === 1 ? 'queued' : 'completed',
          conclusion: polls === 1 ? null : 'success',
        },
      ],
    };
  };

  await rerunFailedCheck(request, 'Gadsotek', 'artifactflow', headSha, 12, {
    maxAttempts: 3,
    sleep: async () => {},
  });
  assert.equal(polls, 2);
  assert.equal(reruns, 0);
});

test('a PR check that stays pending fails closed after bounded polling', async () => {
  const headSha = '9'.repeat(40);
  let polls = 0;
  let sleeps = 0;
  const request = async () => {
    polls += 1;
    return {
      workflow_runs: [
        {
          id: 9001,
          head_sha: headSha,
          event: 'pull_request_target',
          display_title: 'CLA PR #12',
          run_number: 7,
          status: 'in_progress',
          conclusion: null,
        },
      ],
    };
  };

  await assert.rejects(
    rerunFailedCheck(request, 'Gadsotek', 'artifactflow', headSha, 12, {
      maxAttempts: 3,
      sleep: async () => {
        sleeps += 1;
      },
    }),
    /pending.*timed out/,
  );
  assert.equal(polls, 3);
  assert.equal(sleeps, 2);
});

test('a deleted triggering signature comment fails closed', async () => {
  const event = {
    repository: { id: 456, name: 'artifactflow', owner: { login: 'Gadsotek' } },
    action: 'created',
    issue: { number: 12, pull_request: {} },
    comment: { id: 101, body: CLA_SIGN_COMMENT, user: { ...contributor, type: 'User' } },
  };
  const request = async (method, path) => {
    if (method === 'GET' && path.endsWith('/pulls/12')) {
      return { state: 'open', user: contributor, head: { sha: '9'.repeat(40) } };
    }
    if (method === 'GET' && path.includes('/issues/12/comments?')) return [];
    if (method === 'GET' && path.endsWith('/issues/comments/101'))
      return { id: 101, body: 'deleted', user: contributor };
    throw new Error(`Unexpected API call: ${method} ${path}`);
  };
  const graphql = async () => ({
    repository: {
      pullRequest: {
        commits: {
          totalCount: 1,
          edges: [{ node: { commit: commit('a'.repeat(40), contributor) } }],
          pageInfo: { hasNextPage: false },
        },
      },
    },
  });

  await assert.rejects(
    runCla(event, 'issue_comment', { request, graphql }),
    /triggering PR comment changed/,
  );
});

test('a signature storage failure prevents a successful CLA check', async () => {
  const event = {
    repository: { id: 456, name: 'artifactflow', owner: { login: 'Gadsotek' } },
    action: 'opened',
    pull_request: { number: 12 },
  };
  const calls = [];
  const request = async (method, path) => {
    calls.push(`${method} ${path}`);
    if (method === 'GET' && path.endsWith('/pulls/12')) {
      return { state: 'open', user: contributor, head: { sha: '9'.repeat(40) } };
    }
    if (method === 'GET' && path.includes('/issues/12/comments?')) {
      return [
        {
          id: 101,
          body: CLA_SIGN_COMMENT,
          user: contributor,
          created_at: '2026-11-08T12:00:00Z',
        },
      ];
    }
    if (method === 'GET' && path.includes('/contents/signatures/version1/cla.json')) {
      return {
        type: 'file',
        sha: 'existing-sha',
        content: Buffer.from(JSON.stringify({ signedContributors: [] })).toString('base64'),
      };
    }
    if (method === 'PUT' && path.endsWith('/contents/signatures/version1/cla.json')) {
      throw new Error('signature storage unavailable');
    }
    throw new Error(`Unexpected API call: ${method} ${path}`);
  };
  const graphql = async () => ({
    repository: {
      pullRequest: {
        commits: {
          totalCount: 1,
          edges: [{ node: { commit: commit('a'.repeat(40), contributor) } }],
          pageInfo: { hasNextPage: false },
        },
      },
    },
  });

  await assert.rejects(
    runCla(event, 'pull_request_target', { request, graphql }),
    /signature storage unavailable/,
  );
  assert.equal(
    calls.some((call) => call.includes('/actions/runs/')),
    false,
  );
});
