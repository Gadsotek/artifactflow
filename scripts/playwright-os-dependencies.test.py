#!/usr/bin/env python3
"""Exercise the CI mirror rewrite without sudo, network access, or APT writes."""
import argparse
from pathlib import Path
import re
import shlex
import subprocess
import unittest

parser = argparse.ArgumentParser(add_help=False)
parser.add_argument(
    "--workflow", type=Path,
    default=Path(__file__).resolve().parents[1] / ".github/workflows/ci.yml",
)
options, unittest_args = parser.parse_known_args()


class PlaywrightOsDependenciesTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.workflow = options.workflow.read_text()
        match = re.search(
            r"      - name: Install Playwright browser OS dependencies\n(.*?)(?=\n      - name:)",
            cls.workflow, re.S,
        )
        if match is None:
            raise AssertionError("Missing browser OS dependency step")
        cls.step = match.group(1)

    def rewrite(self, source):
        lines = [line.strip() for line in self.step.splitlines() if line.strip().startswith("sudo perl ")]
        self.assertEqual(len(lines), 1, "Use one scoped mirror rewrite before installation")
        command = shlex.split(lines[0])
        self.assertEqual(command[:4], ["sudo", "perl", "-pi", "-e"])
        self.assertEqual(len(command), 6)
        self.assertEqual(command[-1], "/etc/apt/sources.list.d/ubuntu.sources")
        # Test the actual expression against stdin. Never invoke sudo or in-place editing.
        result = subprocess.run(
            ["perl", "-pe", command[4]], input=source, text=True,
            capture_output=True, check=True,
        )
        return result.stdout

    def test_installer_is_bounded_without_weakening_browser_coverage(self):
        self.assertRegex(self.step, r"(?m)^        timeout-minutes: 10$")
        self.assertIn("npx playwright install-deps chromium firefox webkit", self.step)
        self.assertLess(self.step.index("sudo perl "), self.step.index("npx playwright"))
        self.assertNotIn("continue-on-error", self.step)
        self.assertNotIn("|| true", self.step)
        self.assertRegex(self.workflow, r"browser:\n    runs-on: ubuntu-latest\n    timeout-minutes: 50\n")

    def test_exact_azure_mirror_changes_preserve_signed_repository_fields(self):
        source = (
            "Types: deb\nURIs: http://azure.archive.ubuntu.com/ubuntu\n"
            "Suites: noble noble-updates noble-backports noble-security\n"
            "Components: main restricted universe multiverse\nArchitectures: amd64\n"
            "Signed-By: /usr/share/keyrings/ubuntu-archive-keyring.gpg\n"
        )
        self.assertEqual(
            self.rewrite(source),
            source.replace("http://azure.archive.ubuntu.com/ubuntu", "https://archive.ubuntu.com/ubuntu"),
        )

    def test_https_trailing_slash_and_multiple_uri_tokens(self):
        self.assertEqual(
            self.rewrite("URIs: https://azure.archive.ubuntu.com/ubuntu/ http://azure.archive.ubuntu.com/ubuntu\n"),
            "URIs: https://archive.ubuntu.com/ubuntu/ https://archive.ubuntu.com/ubuntu\n",
        )

    def test_security_third_party_comments_and_near_matches_are_unchanged(self):
        source = (
            "URIs: http://security.ubuntu.com/ubuntu\n"
            "URIs: https://packages.microsoft.com/repos/azure-cli\n"
            "# URIs: http://azure.archive.ubuntu.com/ubuntu\n"
            "Description: http://azure.archive.ubuntu.com/ubuntu\n"
            "URIs: http://azure.archive.ubuntu.com/ubuntu-extra\n"
            "URIs: http://azure.archive.ubuntu.com/ubuntu/private\n"
            "URIs: http://azure.archive.ubuntu.com.example/ubuntu\n"
            "URIs: https://example.invalid/http://azure.archive.ubuntu.com/ubuntu\n"
        )
        self.assertEqual(self.rewrite(source), source)

    def test_rewriting_is_idempotent(self):
        source = "URIs: http://azure.archive.ubuntu.com/ubuntu\n"
        once = self.rewrite(source)
        self.assertEqual(self.rewrite(once), once)


if __name__ == "__main__":
    unittest.main(argv=[__file__, *unittest_args])
