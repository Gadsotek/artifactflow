/* global console */

import { readFileSync } from 'node:fs';
import process from 'node:process';
import { URL, fileURLToPath } from 'node:url';

export class DependencyLicenseAudit {
  constructor({ approvedLicenses, npmMetadataOverrides }) {
    if (!Array.isArray(approvedLicenses) || npmMetadataOverrides === null) {
      throw new Error('Dependency license policy has an invalid shape.');
    }

    this.approvedLicenses = new Set(approvedLicenses);
    this.npmMetadataOverrides = npmMetadataOverrides;
  }

  auditComposerPackages(packages, source) {
    const issues = [];

    for (const dependency of packages) {
      const name = typeof dependency.name === 'string' ? dependency.name : '<unknown>';
      const version = typeof dependency.version === 'string' ? dependency.version : '<unknown>';
      const license = Array.isArray(dependency.license)
        ? dependency.license.filter((value) => typeof value === 'string').join(' OR ')
        : '';

      if (!this.isCompatible(license)) {
        issues.push(
          `${source}: Composer package ${name}@${version} has missing or unapproved license ${license || '<missing>'}.`,
        );
      }
    }

    return issues;
  }

  auditNpmPackages(packages, source) {
    const issues = [];

    for (const [packagePath, dependency] of Object.entries(packages)) {
      if (packagePath === '' || dependency === null || typeof dependency.version !== 'string') {
        continue;
      }

      const name = this.npmPackageName(packagePath);
      const version = dependency.version;
      let license = typeof dependency.license === 'string' ? dependency.license.trim() : '';

      if (license === '') {
        const override = this.npmMetadataOverrides[`${name}@${version}`];

        if (
          override !== undefined &&
          typeof override.license === 'string' &&
          typeof override.source === 'string' &&
          override.source.startsWith('https://')
        ) {
          license = override.license;
        }
      }

      if (!this.isCompatible(license)) {
        issues.push(
          `${source}: npm package ${name}@${version} has missing or unapproved license ${license || '<missing>'}.`,
        );
      }
    }

    return issues;
  }

  isCompatible(expression) {
    if (typeof expression !== 'string' || expression.trim() === '') {
      return false;
    }

    let normalized = expression.trim();
    while (normalized.startsWith('(') && normalized.endsWith(')')) {
      normalized = normalized.slice(1, -1).trim();
    }

    if (/\s+(?:AND|WITH)\s+/u.test(normalized)) {
      return false;
    }

    return normalized
      .split(/\s+OR\s+/u)
      .map((license) => license.replace(/^[()\s]+|[()\s]+$/gu, ''))
      .some((license) => this.approvedLicenses.has(license));
  }

  npmPackageName(packagePath) {
    const marker = 'node_modules/';
    const position = packagePath.lastIndexOf(marker);

    return position === -1 ? packagePath : packagePath.slice(position + marker.length);
  }
}

function jsonFile(url) {
  return JSON.parse(readFileSync(url, 'utf8'));
}

export function auditRepositoryLicenses() {
  const root = new URL('../../', import.meta.url);
  const policy = jsonFile(new URL('security/dependency-license-policy.json', root));
  const audit = new DependencyLicenseAudit(policy);
  const composer = jsonFile(new URL('composer.lock', root));
  const npmLocks = [
    ['package-lock.json', jsonFile(new URL('package-lock.json', root))],
    [
      'xlsx-processor-spike/package-lock.json',
      jsonFile(new URL('xlsx-processor-spike/package-lock.json', root)),
    ],
    [
      'scripts/mcp-remote-bridge/package-lock.json',
      jsonFile(new URL('scripts/mcp-remote-bridge/package-lock.json', root)),
    ],
  ];
  const issues = [
    ...audit.auditComposerPackages(
      [...(composer.packages ?? []), ...(composer['packages-dev'] ?? [])],
      'composer.lock',
    ),
    ...npmLocks.flatMap(([source, lock]) => audit.auditNpmPackages(lock.packages ?? {}, source)),
  ];

  if (issues.length > 0) {
    throw new Error(`Dependency license audit failed:\n- ${issues.join('\n- ')}`);
  }

  console.log('Dependency license audit passed for all locked Composer and npm packages.');
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  try {
    auditRepositoryLicenses();
  } catch (error) {
    console.error(error instanceof Error ? error.message : 'Dependency license audit failed.');
    process.exit(1);
  }
}
