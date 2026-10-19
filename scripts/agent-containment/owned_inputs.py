#!/usr/bin/env python3
"""Bound host-side conformance input reads without following filesystem aliases."""
from __future__ import annotations

import errno
import os
from pathlib import Path
import stat

from owned_receipt import _identity, _open_directory


CONTROL_INPUT_LIMIT = 2 * 1024 * 1024


def _verify_directory(path: Path, descriptor: int) -> None:
    current = _open_directory(path)
    try:
        if _identity(os.fstat(current)) != _identity(os.fstat(descriptor)):
            raise OSError(errno.EPERM, 'owned_input_directory_changed')
    finally:
        os.close(current)


def read_owned_bytes(path: Path, limit: int) -> bytes:
    """Read a bounded ordinary input through pinned, no-follow descriptors.

    This is for already selected project controls and newly owned canaries; it
    does not grant access or resolve a model-supplied path into an approved one.
    Nonblocking open lets us reject a substituted FIFO before any read occurs.
    """
    if type(limit) is not int or limit < 1 or path.name in ('', '.', '..'):
        raise OSError(errno.EINVAL, 'owned_input_invalid')
    directory = _open_directory(path.parent)
    try:
        descriptor = os.open(path.name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC,
                             dir_fd=directory)
        try:
            before = os.fstat(descriptor)
            if not stat.S_ISREG(before.st_mode) or before.st_nlink != 1 or before.st_size > limit:
                raise OSError(errno.EPERM, 'owned_input_not_bounded_regular_file')
            data = bytearray()
            while len(data) <= limit:
                chunk = os.read(descriptor, min(65536, limit + 1 - len(data)))
                if not chunk:
                    break
                data.extend(chunk)
            after = os.fstat(descriptor)
            current = os.stat(path.name, dir_fd=directory, follow_symlinks=False)
            if len(data) > limit or not stat.S_ISREG(current.st_mode) \
                    or current.st_nlink != 1 or after.st_nlink != 1 \
                    or _identity(current) != _identity(before) \
                    or before.st_size != len(data) or after.st_size != len(data) \
                    or before.st_mtime_ns != after.st_mtime_ns:
                raise OSError(errno.EPERM, 'owned_input_changed')
            _verify_directory(path.parent, directory)
            return bytes(data)
        finally:
            os.close(descriptor)
    finally:
        os.close(directory)


def list_owned_files(directory: Path, suffixes: tuple[str, ...]) -> list[Path]:
    """Enumerate conformance inputs without traversing an aliased directory."""
    descriptor = _open_directory(directory)
    try:
        listing = os.open('.', os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC,
                          dir_fd=descriptor)
        try:
            names = os.listdir(listing)
        finally:
            os.close(listing)
        _verify_directory(directory, descriptor)
        return [directory / name for name in names if name.endswith(suffixes)]
    finally:
        os.close(descriptor)
