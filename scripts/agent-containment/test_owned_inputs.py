#!/usr/bin/env python3
"""Post-client input regressions using owned files and mocked native clients."""
from __future__ import annotations

from contextlib import ExitStack
import contextlib
import importlib
import io
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import MagicMock, patch


class PostClientInputTest(unittest.TestCase):
    def test_second_client_rejects_canary_alias_left_by_failed_first_client(self):
        development = importlib.import_module('run_development')
        claude = importlib.import_module('claude_session')
        root = Path(tempfile.mkdtemp(prefix='owned-input-second-client-')).resolve()
        project = root / 'project'
        fixture = project / 'storage/framework/testing/fixture'
        fixture.mkdir(parents=True)
        (fixture / 'source.txt').write_text('owned source\n')
        edit_path = fixture / 'agent-edit.txt'
        edit_path.write_text(claude.SOURCE_BEFORE)
        outside = root / 'owned-outside.txt'
        outside.write_text('owned outside content must not be read\n')

        def first_client(*_args, **_kwargs):
            edit_path.unlink()
            edit_path.symlink_to(outside)
            return {'passed': False}

        with patch.object(development.sys, 'argv', ['run_development', '--run', '--project-root', str(project)]), \
                patch.object(development, 'snapshot_controls', return_value={}), \
                patch.object(development, 'create_fixtures', return_value=(fixture, outside)), \
                patch.object(development, 'run_codex_session', side_effect=first_client), \
                patch.object(claude.shutil, 'which', return_value='/synthetic/claude'), \
                patch.object(claude, 'run_native') as native, \
                patch.object(claude, 'read_owned_bytes') as read, \
                contextlib.redirect_stdout(io.StringIO()):
            self.assertEqual(1, development.main())
        native.assert_not_called()
        read.assert_not_called()

    def test_post_client_integrity_snapshots_reject_outside_aliases(self):
        development = importlib.import_module('run_development')
        for name in ('run_development', 'claude_session', 'run_claude'):
            with self.subTest(runner=name):
                module = importlib.import_module(name)
                root = Path(tempfile.mkdtemp(prefix='owned-input-hashes-')).resolve()
                project = root / 'project'
                fixture = project / 'storage/framework/testing/fixture'
                fixture.mkdir(parents=True)
                for relative in (*development.CONTROL_FILES, 'probe.py', 'batch.py',
                                 'owned-specs.json', 'owned-options.json'):
                    path = project / relative
                    path.parent.mkdir(parents=True, exist_ok=True)
                    path.write_text('owned control input\n')
                outside = root / 'owned-outside.txt'
                outside.write_text('owned outside input must not be read\n')
                alias = project / ('owned-specs.json' if name == 'run_claude' else 'scripts/publish-guard.sh')
                if name == 'claude_session':
                    replacement = root / 'owned-outside-directory'
                    replacement.mkdir()
                    (replacement / 'owned-replacement.json').write_text('owned outside input\n')
                    fixture.rename(fixture.with_name('retained'))
                    fixture.symlink_to(replacement, target_is_directory=True)
                else:
                    alias.unlink()
                    alias.symlink_to(outside)
                with self.assertRaises(OSError):
                    if name == 'run_development':
                        module.snapshot_controls(project)
                    elif name == 'claude_session':
                        module.project_hashes(project, fixture)
                    else:
                        module.fixture_hashes(project, alias, project / 'owned-options.json')

    def test_codex_failure_cleanup_never_reads_through_changed_fixture_ancestor(self):
        for name in ('codex_model_session', 'codex_permission_session'):
            with self.subTest(runner=name):
                module = importlib.import_module(name)
                root = Path(tempfile.mkdtemp(prefix='owned-input-regression-')).resolve()
                project = root / 'project'
                fixture = project / 'storage/framework/testing/fixture'
                fixture.mkdir(parents=True)
                prefix = 'hook' if name == 'codex_model_session' else 'permission'
                sentinel = f'artifactflow-owned-{prefix}-canary-synthetic\n'.encode()
                (fixture / 'source.txt').write_bytes(sentinel)
                approved = root / 'codex-permission-outside-owned/source.txt'
                approved.parent.mkdir()
                approved.write_bytes(sentinel)
                outside = root / 'owned-outside'
                outside.mkdir()
                target = outside / 'source.txt'
                target.write_bytes(b'owned-outside-content-must-not-be-read\n')
                followed = []
                original_read = Path.read_bytes

                def read(path):
                    if path.resolve() == target:
                        followed.append(path)
                    return original_read(path)

                def mutate(*_args):
                    fixture.rename(fixture.with_name('retained'))
                    fixture.symlink_to(outside, target_is_directory=True)
                    return []

                rpc = MagicMock()
                rpc.warning_count = 0
                rpc.turn_id = 'owned-turn'
                rpc.request.side_effect = [{}, {}, {}, {'thread': {'id': 'owned-thread'}},
                                           {'turn': {'id': 'owned-turn'}}]
                rpc.collect_turn.side_effect = mutate
                rpc.cancel_response = None
                with ExitStack() as stack:
                    stack.enter_context(patch.object(Path, 'read_bytes', read))
                    for function, value in (('snapshot_controls', {}), ('read_project', ({}, {}, 'c', 'h')),
                                            ('resolve_codex', '/synthetic/codex'), ('project_evidence', {}),
                                            ('hook_evidence', {'warning_count': 0}), ('thread_evidence', {})):
                        stack.enter_context(patch.object(module, function, return_value=value))
                    if name == 'codex_model_session':
                        stack.enter_context(patch.object(module, 'EventAppServer', return_value=rpc))
                        stack.enter_context(patch('codex_hook_evidence.validate_model_evidence', return_value={'passed': False}))
                        result = module.run_session(project, fixture)
                    else:
                        stack.enter_context(patch.object(module, 'PermissionAppServer', return_value=rpc))
                        stack.enter_context(patch.object(module, 'validate_outside_parent'))
                        stack.enter_context(patch.object(module, 'validate_permission_evidence', return_value={'passed': False}))
                        result = module.run_session(project, fixture, approved)
                self.assertFalse(result['passed'])
                rpc.collect_turn.assert_called_once()
                self.assertEqual([], followed, 'Host cleanup must reject the alias before reading the outside file.')

    def test_claude_post_client_edit_check_never_reads_changed_leaf_or_ancestor(self):
        module = importlib.import_module('claude_session')
        for attack in ('leaf', 'ancestor'):
            with self.subTest(attack=attack):
                root = Path(tempfile.mkdtemp(prefix='owned-input-claude-')).resolve()
                project = root / 'project'
                fixture = project / 'storage/framework/testing/fixture'
                fixture.mkdir(parents=True)
                read_path, edit_path = fixture / 'source.txt', fixture / 'agent-edit.txt'
                read_path.write_text('owned source\n')
                edit_path.write_text(module.SOURCE_BEFORE)
                outside = root / 'owned-outside'
                outside.mkdir()
                target = outside / edit_path.name
                target.write_text('owned-outside-content-must-not-be-read\n')
                outside_read = root / 'owned-outside-read.txt'
                outside_read.write_text('owned outside sentinel\n')
                followed = []
                original_read = Path.read_text

                def read(path, *args, **kwargs):
                    if path.resolve() == target:
                        followed.append(path)
                    return original_read(path, *args, **kwargs)

                def mutate(*_args):
                    if attack == 'leaf':
                        edit_path.unlink()
                        edit_path.symlink_to(target)
                    else:
                        fixture.rename(fixture.with_name('retained'))
                        fixture.symlink_to(outside, target_is_directory=True)
                    return subprocess.CompletedProcess([], 1, '', '')

                with patch.object(module.shutil, 'which', return_value='/synthetic/claude'), \
                        patch.object(module, 'project_hashes', return_value={}), \
                        patch.object(module, 'build_launch', return_value=([], 'owned prompt')), \
                        patch.object(module, 'run_native', side_effect=mutate) as run, \
                        patch.object(Path, 'read_text', read):
                    result = module.run_session(project, fixture, 'owned command', {}, read_path,
                                                edit_path, outside_path=outside_read)
                self.assertFalse(result['passed'])
                run.assert_called_once()
                self.assertEqual([], followed, 'Host edit verification must reject filesystem aliases before reading.')


class OwnedInputReaderTest(unittest.TestCase):
    def setUp(self):
        self.module = importlib.import_module('owned_inputs')
        self.root = Path(tempfile.mkdtemp(prefix='owned-input-reader-')).resolve()
        self.directory = self.root / 'fixture'
        self.directory.mkdir()
        self.source = self.directory / 'source.txt'
        self.source.write_bytes(b'owned canary\n')
        self.outside = self.root / 'owned-outside.txt'
        self.outside.write_bytes(b'outside owned canary\n')

    def test_regular_canary_read_is_bounded_and_names_are_listed_without_aliases(self):
        self.assertEqual(b'owned canary\n', self.module.read_owned_bytes(self.source, 128))
        self.assertEqual([self.source], self.module.list_owned_files(self.directory, ('.txt',)))

    def test_nonregular_hardlinked_aliased_and_oversized_inputs_fail_before_read(self):
        alias = self.directory / 'alias.txt'
        alias.symlink_to(self.outside)
        hardlink = self.directory / 'hardlink.txt'
        os.link(self.outside, hardlink)
        fifo = self.directory / 'pipe.txt'
        os.mkfifo(fifo)
        ancestor = self.root / 'alias-directory'
        ancestor.symlink_to(self.directory, target_is_directory=True)
        for path, limit in ((alias, 128), (hardlink, 128), (fifo, 128),
                            (ancestor / self.source.name, 128), (self.source, 1)):
            with self.subTest(path=path), patch.object(self.module.os, 'read') as read, self.assertRaises(OSError):
                self.module.read_owned_bytes(path, limit)
            read.assert_not_called()
        with self.assertRaises(OSError):
            self.module.list_owned_files(ancestor, ('.txt',))

    def test_leaf_swap_after_open_never_redirects_read(self):
        real_open, real_read = os.open, os.read
        outside_identity = (self.outside.stat().st_dev, self.outside.stat().st_ino)
        reads = []

        def opened(path, *args, **kwargs):
            descriptor = real_open(path, *args, **kwargs)
            if path == self.source.name:
                self.source.rename(self.directory / 'retained.txt')
                self.source.symlink_to(self.outside)
            return descriptor

        def read(descriptor, size):
            value = os.fstat(descriptor)
            reads.append((value.st_dev, value.st_ino))
            return real_read(descriptor, size)

        with patch.object(self.module.os, 'open', side_effect=opened), \
                patch.object(self.module.os, 'read', side_effect=read), self.assertRaises(OSError):
            self.module.read_owned_bytes(self.source, 128)
        self.assertNotIn(outside_identity, reads)

    def test_parent_swap_after_open_never_redirects_read(self):
        outside = self.root / 'owned-outside-directory'
        outside.mkdir()
        (outside / self.source.name).write_bytes(b'outside owned canary\n')
        real_open = self.module._open_directory
        calls = 0

        def opened(path):
            nonlocal calls
            descriptor = real_open(path)
            calls += 1
            if calls == 1:
                self.directory.rename(self.root / 'retained')
                self.directory.symlink_to(outside, target_is_directory=True)
            return descriptor

        with patch.object(self.module, '_open_directory', side_effect=opened), self.assertRaises(OSError):
            self.module.read_owned_bytes(self.source, 128)


if __name__ == '__main__':
    unittest.main(verbosity=2)
