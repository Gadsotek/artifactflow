"""Bound cleanup to the POSIX process group created by a launcher we own."""
from __future__ import annotations

import os
import signal
import subprocess
import time


def stop_owned_process_group(process: subprocess.Popen, grace_seconds: float = 5) -> None:
    """Stop surviving group members even after the direct child has exited.

    Call only for a child started with start_new_session=True. Closed stdout
    pipes and a reaped group leader do not establish descendant termination.
    This does not enumerate other processes or pursue a child that creates a
    different session. A remaining owned group receives SIGKILL after grace.
    """
    try:
        os.killpg(process.pid, signal.SIGTERM)
    except ProcessLookupError:
        process.wait(timeout=5)
        return
    deadline = time.monotonic() + grace_seconds
    while True:
        # Reap the direct child so an otherwise empty group disappears.
        process.poll()
        try:
            os.killpg(process.pid, 0)
        except ProcessLookupError:
            break
        except PermissionError:
            # Some hosts deny the liveness probe while a child is exiting.
            # Unknown liveness is never successful cleanup: keep the deadline,
            # then require SIGKILL to succeed if the group has not disappeared.
            pass
        remaining = deadline - time.monotonic()
        if remaining <= 0:
            try:
                os.killpg(process.pid, signal.SIGKILL)
            except ProcessLookupError:
                pass
            break
        time.sleep(min(.02, remaining))
    process.wait(timeout=5)
