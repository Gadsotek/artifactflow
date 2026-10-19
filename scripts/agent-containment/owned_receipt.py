#!/usr/bin/env python3
"""Write host receipts without following model-controlled filesystem aliases."""
from __future__ import annotations

import errno
import json
import os
from pathlib import Path
import stat
import uuid


def _open_directory(path: Path) -> int:
    """Walk an absolute directory without following any symlink component."""
    if not path.is_absolute() or '..' in path.parts:
        raise OSError(errno.EINVAL, 'receipt_directory_invalid')
    lookup = getattr(os, 'O_PATH', getattr(os, 'O_SEARCH', os.O_RDONLY))
    flags = lookup | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC
    descriptor = os.open(path.anchor, flags)
    try:
        for component in path.parts[1:]:
            child = os.open(component, flags, dir_fd=descriptor)
            os.close(descriptor)
            descriptor = child
        return descriptor
    except BaseException:
        os.close(descriptor)
        raise


def _identity(value: os.stat_result) -> tuple[int, int]:
    return value.st_dev, value.st_ino


class OwnedReceipt:
    """Pin a fresh fixture directory before any untrusted client executes.

    Writes use only its descriptor. Rechecking its pathname detects moved/replaced
    fixtures; the descriptor still protects writes if a replacement races a check.
    Atomic replacement never opens an existing receipt or follows its hard links.
    """

    def __init__(self, directory: Path, name: str):
        if not name or Path(name).name != name or name in ('.', '..'):
            raise OSError(errno.EINVAL, 'receipt_name_invalid')
        self.directory = directory
        self.name = name
        self.descriptor = _open_directory(directory)
        self.identity = _identity(os.fstat(self.descriptor))

    def __enter__(self):
        return self

    def __exit__(self, *_args):
        self.close()

    def close(self) -> None:
        if self.descriptor is not None:
            os.close(self.descriptor)
            self.descriptor = None

    def _verify_directory(self) -> None:
        if self.descriptor is None:
            raise OSError(errno.EBADF, 'receipt_directory_closed')
        current = _open_directory(self.directory)
        try:
            if _identity(os.fstat(current)) != self.identity:
                raise OSError(errno.EPERM, 'receipt_directory_changed')
        finally:
            os.close(current)

    def write(self, value: dict) -> None:
        self._verify_directory()
        try:
            existing = os.stat(self.name, dir_fd=self.descriptor, follow_symlinks=False)
        except FileNotFoundError:
            existing = None
        if existing is not None and (not stat.S_ISREG(existing.st_mode) or existing.st_nlink != 1):
            raise OSError(errno.EPERM, 'receipt_target_changed')
        temporary = '.receipt-' + uuid.uuid4().hex
        descriptor = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW | os.O_CLOEXEC,
                             0o600, dir_fd=self.descriptor)
        try:
            with os.fdopen(descriptor, 'w', encoding='utf-8') as stream:
                json.dump(value, stream, indent=2)
                stream.write('\n')
                stream.flush()
                written = _identity(os.fstat(stream.fileno()))
                self._verify_directory()
                os.replace(temporary, self.name, src_dir_fd=self.descriptor, dst_dir_fd=self.descriptor)
                actual = os.stat(self.name, dir_fd=self.descriptor, follow_symlinks=False)
                if _identity(actual) != written or not stat.S_ISREG(actual.st_mode) or actual.st_nlink != 1:
                    raise OSError(errno.EPERM, 'receipt_target_changed')
                self._verify_directory()
        finally:
            try:
                os.unlink(temporary, dir_fd=self.descriptor)
            except FileNotFoundError:
                pass
