const EDITING_TTL_MS = 90_000;
const HEARTBEAT_MS = 30_000;
const KEYDOWN_DEBOUNCE_MS = 500;
const SIGNAL_MIN_INTERVAL_MS = 5_000;

const mount = document.querySelector('[data-page-presence]');
const versionNotice = document.querySelector('[data-page-version-notice]');
const { Echo } = window;

if (mount instanceof HTMLElement && Echo) {
  const pageUid = mount.dataset.pagePresencePageUid ?? '';
  const presenceEndpoint = mount.dataset.pagePresenceEndpoint ?? '';
  const currentUserUid = mount.dataset.pagePresenceCurrentUserUid ?? '';
  const currentVersionUid =
    versionNotice instanceof HTMLElement ? (versionNotice.dataset.currentVersionUid ?? '') : '';
  const status = mount.querySelector('[data-page-presence-status]');
  const editingWarning = document.querySelector('[data-page-presence-editing-warning]');
  const editingWarningStatus =
    editingWarning instanceof HTMLElement
      ? editingWarning.querySelector('[data-page-presence-editing-warning-status]')
      : null;
  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
  const knownMembers = new Map();
  const editingMembers = new Map();
  let heartbeatId = null;
  let keydownTimer = null;
  let editorHasFocus = false;
  let lastEditingSignalAt = 0;

  const identityFrom = (member) => {
    if (!member || typeof member.uid !== 'string' || member.uid.trim() === '') {
      return null;
    }

    return {
      uid: member.uid,
      name: typeof member.name === 'string' && member.name.trim() !== '' ? member.name : 'Someone',
    };
  };

  const rememberMember = (member) => {
    const identity = identityFrom(member);

    if (identity === null) {
      return;
    }

    knownMembers.set(identity.uid, identity.name);
  };

  const clearMember = (member) => {
    const identity = identityFrom(member);

    if (identity === null) {
      return;
    }

    knownMembers.delete(identity.uid);
    editingMembers.delete(identity.uid);
    renderPresence();
  };

  const pruneExpiredEditors = () => {
    const now = Date.now();

    for (const [uid, editor] of editingMembers.entries()) {
      if (editor.expiresAt <= now) {
        editingMembers.delete(uid);
      }
    }
  };

  const describeEditors = (editors) => {
    if (editors.length === 1) {
      return `${editors[0]} is editing`;
    }

    if (editors.length === 2) {
      return `${editors[0]} and ${editors[1]} are editing`;
    }

    return `${editors[0]} and ${editors.length - 1} others are editing`;
  };

  const renderEditingWarning = (editors) => {
    if (!(editingWarning instanceof HTMLElement)) {
      return;
    }

    if (editors.length === 0) {
      editingWarning.hidden = true;

      return;
    }

    if (editingWarningStatus instanceof HTMLElement) {
      editingWarningStatus.textContent = `${describeEditors(editors)} right now — saving at the same time may cause a version conflict.`;
    }

    editingWarning.hidden = false;
  };

  function renderPresence() {
    pruneExpiredEditors();

    const editors = Array.from(editingMembers.entries())
      .filter(([uid]) => uid !== currentUserUid)
      .map(([, editor]) => editor.name);

    // The in-dialog warning is independent of the header badge, so update it
    // before the badge's own early-out below.
    renderEditingWarning(editors);

    if (!(status instanceof HTMLElement) || editors.length === 0) {
      mount.hidden = true;

      return;
    }

    status.textContent = describeEditors(editors);
    mount.hidden = false;
  }

  const markEditing = (uid, name) => {
    if (uid === currentUserUid || !knownMembers.has(uid)) {
      return;
    }

    editingMembers.set(uid, {
      name: typeof name === 'string' && name.trim() !== '' ? name : knownMembers.get(uid),
      expiresAt: Date.now() + EDITING_TTL_MS,
    });
    renderPresence();
  };

  const clearEditing = (uid) => {
    if (uid === currentUserUid) {
      return;
    }

    editingMembers.delete(uid);
    renderPresence();
  };

  Echo.join(`page.${pageUid}`)
    .here((members) => {
      knownMembers.clear();

      for (const member of members) {
        rememberMember(member);
      }

      renderPresence();
    })
    .joining((member) => {
      rememberMember(member);
      renderPresence();

      // A joining member's presence handshake carries identity only, not our
      // current editing flag, so re-announce when we hold the editor. Without
      // this they would not learn we are editing until our next heartbeat.
      if (editorHasFocus) {
        signalEditing(true);
      }
    })
    .leaving((member) => {
      clearMember(member);
    })
    .listen('.page.editing', (payload) => {
      if (!payload || typeof payload.uid !== 'string') {
        return;
      }

      if (typeof payload.name === 'string' && payload.name.trim() !== '') {
        knownMembers.set(payload.uid, payload.name);
      }

      if (payload.editing === true) {
        markEditing(payload.uid, payload.name);
      } else {
        clearEditing(payload.uid);
      }
    })
    .listen('.page.access.revoked', (payload) => {
      if (!payload || typeof payload.uid !== 'string') {
        return;
      }

      knownMembers.delete(payload.uid);
      editingMembers.delete(payload.uid);
      renderPresence();

      if (payload.uid === currentUserUid) {
        stopHeartbeat();
        Echo.leave(`page.${pageUid}`);
        knownMembers.clear();
        editingMembers.clear();
        renderPresence();
      }
    })
    .listen('.page.version.created', (payload) => {
      const versionUid = payload?.version_uid;

      if (
        !(versionNotice instanceof HTMLElement) ||
        !payload ||
        payload.page_uid !== pageUid ||
        typeof versionUid !== 'string' ||
        versionUid === '' ||
        versionUid === currentVersionUid
      ) {
        return;
      }

      versionNotice.hidden = false;
    });

  const stopHeartbeat = () => {
    if (heartbeatId !== null) {
      window.clearInterval(heartbeatId);
      heartbeatId = null;
    }
  };

  const sendPresenceState = (state, keepalive = false) => {
    if (presenceEndpoint === '' || csrfToken === '') {
      return;
    }

    window
      .fetch(presenceEndpoint, {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrfToken,
        },
        body: JSON.stringify({ state }),
        credentials: 'same-origin',
        keepalive,
      })
      .catch(() => {});
  };

  const signalEditing = (force = false) => {
    if (currentUserUid === '') {
      return;
    }

    const now = Date.now();

    if (force || now - lastEditingSignalAt >= SIGNAL_MIN_INTERVAL_MS) {
      lastEditingSignalAt = now;
      sendPresenceState('editing');
    }

    if (heartbeatId === null) {
      heartbeatId = window.setInterval(() => {
        if (editorHasFocus) {
          signalEditing(true);
        } else {
          stopHeartbeat();
        }
      }, HEARTBEAT_MS);
    }
  };

  const signalStoppedEditing = (keepalive = false) => {
    stopHeartbeat();

    if (currentUserUid !== '') {
      sendPresenceState('idle', keepalive);
    }
  };

  const scheduleKeySignal = () => {
    if (keydownTimer !== null) {
      window.clearTimeout(keydownTimer);
    }

    keydownTimer = window.setTimeout(() => {
      keydownTimer = null;
      signalEditing();
    }, KEYDOWN_DEBOUNCE_MS);
  };

  for (const editor of document.querySelectorAll('[data-content-editor]')) {
    editor.addEventListener('focusin', () => {
      editorHasFocus = true;
      signalEditing(true);
    });

    editor.addEventListener('keydown', () => {
      editorHasFocus = true;
      scheduleKeySignal();
    });

    editor.addEventListener('focusout', () => {
      window.setTimeout(() => {
        if (!editor.contains(document.activeElement)) {
          editorHasFocus = false;
          signalStoppedEditing();
        }
      }, 0);
    });
  }

  window.setInterval(renderPresence, 10_000);
  window.addEventListener('beforeunload', () => signalStoppedEditing(true));
}
