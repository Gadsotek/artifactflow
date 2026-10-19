#!/usr/bin/env python3
"""Owned-only receipt boundary regressions; no native clients or model calls."""
from __future__ import annotations

import contextlib
import importlib
import io
import json
import os
import subprocess
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch


class OwnedReceiptTest(unittest.TestCase):
    def setUp(self):
        self.module = importlib.import_module('owned_receipt')
        self.root = Path(tempfile.mkdtemp(prefix='owned-receipt-unit-')).resolve()
        self.fixture = self.root / 'fixture'
        self.fixture.mkdir()
        self.target = self.root / 'owned-target.txt'
        self.target.write_text('owned-target-must-remain\n')

    def test_atomic_create_and_update_keep_private_mode_and_close_descriptor(self):
        with self.module.OwnedReceipt(self.fixture, 'receipt.json') as receipt:
            descriptor = receipt.descriptor
            receipt.write({'passed': False})
            receipt.write({'passed': True})
            self.assertEqual({'passed': True}, json.loads((self.fixture / 'receipt.json').read_text()))
            self.assertEqual(0o600, (self.fixture / 'receipt.json').stat().st_mode & 0o777)
        with self.assertRaises(OSError):
            os.fstat(descriptor)
        self.assertEqual(['receipt.json'], [path.name for path in self.fixture.iterdir()])

    def test_hardlinked_destination_is_rejected_without_touching_target(self):
        with self.module.OwnedReceipt(self.fixture, 'receipt.json') as receipt:
            os.link(self.target, self.fixture / 'receipt.json')
            with self.assertRaises(OSError):
                receipt.write({'passed': False})
        self.assertEqual('owned-target-must-remain\n', self.target.read_text())

    def test_leaf_replacement_racing_check_cannot_redirect_atomic_write(self):
        real_replace = os.replace
        with self.module.OwnedReceipt(self.fixture, 'receipt.json') as receipt:
            def swap_then_replace(*args, **kwargs):
                (self.fixture / 'receipt.json').symlink_to(self.target)
                return real_replace(*args, **kwargs)
            with patch.object(self.module.os, 'replace', side_effect=swap_then_replace):
                receipt.write({'passed': False})
        self.assertEqual('owned-target-must-remain\n', self.target.read_text())
        self.assertFalse((self.fixture / 'receipt.json').is_symlink())

    def test_ancestor_replacement_racing_check_stays_anchored_and_reports_failure(self):
        outside = self.root / 'owned-outside'
        outside.mkdir()
        target = outside / 'receipt.json'
        target.write_text('owned-target-must-remain\n')
        with self.module.OwnedReceipt(self.fixture, 'receipt.json') as receipt:
            verify = receipt._verify_directory
            calls = 0
            def swap_after_check():
                nonlocal calls
                verify()
                calls += 1
                if calls == 2:
                    self.fixture.rename(self.root / 'retained-fixture')
                    self.fixture.symlink_to(outside, target_is_directory=True)
            with patch.object(receipt, '_verify_directory', side_effect=swap_after_check), self.assertRaises(OSError):
                receipt.write({'passed': False})
        self.assertEqual('owned-target-must-remain\n', target.read_text())

    def test_existing_alias_or_invalid_filename_is_rejected_before_write(self):
        alias = self.root / 'alias'
        alias.symlink_to(self.fixture, target_is_directory=True)
        for directory, name in ((alias, 'receipt.json'), (self.fixture, '../receipt.json')):
            with self.subTest(directory=directory, name=name), self.assertRaises(OSError):
                self.module.OwnedReceipt(directory, name)

    def test_failed_serialization_removes_only_its_owned_temporary_file(self):
        with self.module.OwnedReceipt(self.fixture, 'receipt.json') as receipt:
            with self.assertRaises(TypeError):
                receipt.write({'invalid': object()})
        self.assertEqual([], list(self.fixture.iterdir()))


class ReceiptRunnerBoundaryTest(unittest.TestCase):
    def test_model_writable_receipt_and_ancestor_aliases_never_overwrite_host_targets(self):
        runners = (
            ('codex_permission_session', 'codex-permission-receipt.json'),
            ('codex_model_session', 'codex-model-hooks-receipt.json'),
            ('run_development', 'development-receipt.json'),
            ('run_codex', 'receipt.json'),
            ('run_claude', 'claude-receipt.json'),
        )
        for name, receipt_name in runners:
            for attack in ('receipt', 'fixture', 'ancestor'):
                with self.subTest(runner=name, attack=attack):
                    runner = importlib.import_module(name)
                    root = Path(tempfile.mkdtemp(prefix='owned-receipt-regression-')).resolve()
                    project = root / 'project'
                    fixture = project / 'storage/framework/testing/fixture'
                    fixture.mkdir(parents=True)
                    outside = root / 'owned-outside'
                    outside.mkdir()
                    if attack == 'ancestor':
                        outside = outside / fixture.name
                        outside.mkdir()
                    target = outside / receipt_name
                    target.write_text('owned-host-target-must-remain\n')

                    def malicious_session(*args, **kwargs):
                        if attack == 'receipt':
                            (fixture / receipt_name).symlink_to(target)
                        else:
                            moved = fixture if attack == 'fixture' else fixture.parent
                            moved.rename(moved.with_name(moved.name + '-retained'))
                            moved.symlink_to(outside if attack == 'fixture' else outside.parent,
                                             target_is_directory=True)
                        if name == 'run_claude':
                            return subprocess.CompletedProcess([], 1, '', '')
                        return {'passed': False, 'stage': 'native_evidence',
                                'category': 'unexpected_model_tool'}

                    argv = [name, '--run', '--project-root', str(project)]
                    with contextlib.ExitStack() as stack:
                        stack.enter_context(patch.object(sys, 'argv', argv))
                        stack.enter_context(contextlib.redirect_stdout(io.StringIO()))
                        if name == 'codex_model_session':
                            stack.enter_context(patch.object(runner.tempfile, 'mkdtemp', return_value=str(fixture)))
                            stack.enter_context(patch.object(runner, 'run_session', side_effect=malicious_session))
                        elif name == 'codex_permission_session':
                            stack.enter_context(patch.object(runner, 'create_fixtures', return_value=(fixture, outside / 'source.txt')))
                            stack.enter_context(patch.object(runner, 'run_session', side_effect=malicious_session))
                        elif name == 'run_development':
                            argv.extend(['--client', 'codex'])
                            stack.enter_context(patch.object(runner, 'snapshot_controls', return_value={}))
                            stack.enter_context(patch.object(runner, 'create_fixtures', return_value=(fixture, outside / 'source.txt')))
                            stack.enter_context(patch.object(runner, 'run_codex_session', side_effect=malicious_session))
                        else:
                            workspace = fixture / 'workspace'
                            workspace.mkdir()
                            files = {key: workspace / key for key in ('probe', 'allowed', 'outside', 'secret')}
                            stack.enter_context(patch.object(runner, 'create_fixtures', return_value=(fixture, workspace, files)))
                            stack.enter_context(patch.object(runner, 'ambient_preflight'))
                            if name == 'run_codex':
                                argv.remove('--run')
                                stack.enter_context(patch.object(runner, 'load_candidate', return_value=({'filesystem': {}}, 'hash')))
                                stack.enter_context(patch.object(runner, 'resolve_codex', return_value='/synthetic/codex'))
                                stack.enter_context(patch.object(runner, 'probe_runtime_metadata', return_value={
                                    'executable': '/synthetic/python', 'resolved_executable': '/synthetic/python',
                                    'base_prefix': '/synthetic'}))
                                stack.enter_context(patch.object(runner, 'run_case', side_effect=malicious_session))
                                version = 'codex 0.162.0'
                            else:
                                settings = project / '.claude'
                                settings.mkdir()
                                (settings / 'settings.json').write_text('{}')
                                stack.enter_context(patch.object(runner, 'validate_candidate', return_value={}))
                                stack.enter_context(patch.object(runner, 'copy_hooks'))
                                stack.enter_context(patch.object(runner, 'make_specs', return_value=[]))
                                stack.enter_context(patch.object(runner, 'build_launch', return_value=(['synthetic'], 'owned', 'owned')))
                                stack.enter_context(patch.object(runner, 'fixture_hashes', return_value={}))
                                stack.enter_context(patch.object(runner.shutil, 'which', return_value='/synthetic/claude'))
                                stack.enter_context(patch.object(runner, 'run_native', side_effect=malicious_session))
                                version = '2.1.299'
                            stack.enter_context(patch.object(runner.subprocess, 'run', return_value=subprocess.CompletedProcess([], 0, version, '')))
                        self.assertNotEqual(0, runner.main())
                    self.assertEqual('owned-host-target-must-remain\n', target.read_text())


if __name__ == '__main__':
    unittest.main(verbosity=2)
