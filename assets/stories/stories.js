(() => {
  if (!window.KoopoStories) return;

  const API_BASE = window.KoopoStories.restUrl; // .../koopo/v1/stories
  const NONCE = window.KoopoStories.nonce;

  const headers = () => ({
    'X-WP-Nonce': NONCE,
  });

  async function apiGet(url) {
    const res = await fetch(url, { credentials: 'same-origin', headers: headers() });
    if (!res.ok) throw new Error('Request failed');
    return res.json();
  }

  async function apiPost(url, body) {
    const res = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: headers(),
      body,
    });
    if (!res.ok) {
      let msg = 'Request failed';
      try { const j = await res.json(); msg = j.message || j.error || msg; } catch(e){}
      throw new Error(msg);
    }
    return res.json();
  }

  function el(tag, attrs = {}, children = []) {
    const node = document.createElement(tag);
    Object.entries(attrs).forEach(([k,v]) => {
      if (k === 'class') node.className = v;
      else if (k.startsWith('data-')) node.setAttribute(k, v);
      else if (k === 'html') node.innerHTML = v;
      else node.setAttribute(k, v);
    });
    children.forEach(c => node.appendChild(c));
    return node;
  }

  // Viewer singleton
  const Viewer = (() => {
    let root, barsWrap, headerAvatar, headerName, closeBtn, reportBtn, stage, tapPrev, tapNext, headerAvatarLink, viewCount, reactionCount, muteBtn;
    let bottomBar, reactionBtn, replyBtn;
    let story = null;
    let allStories = [];  // All available stories in the tray
    let currentStoryIndex = 0;  // Index in allStories array
    let itemIndex = 0;
    let raf = null;
    let startTs = 0;
    let duration = 5000;
    let paused = false;
    let isMuted = true;

    function ensure() {
      if (root) return;
      barsWrap = el('div', { class: 'koopo-stories__progress' });
      headerAvatar = el('img', { src: '' });
      headerAvatarLink = el('a', { href: '#', class: 'koopo-stories__avatar-link' }, [headerAvatar]);
      headerName = el('div', { class: 'koopo-stories__who', html: '' });

      // Stats container (views and reactions)
      const statsWrap = el('div', { style: 'margin-left:auto;display:flex;gap:12px;align-items:center;' });
      viewCount = el('div', { class: 'koopo-stories__view-count', style: 'font-size:12px;opacity:0.8;cursor:pointer;', html: '' });
      reactionCount = el('div', { class: 'koopo-stories__reaction-count', style: 'font-size:12px;opacity:0.8;', html: '' });
      statsWrap.appendChild(viewCount);
      statsWrap.appendChild(reactionCount);

      muteBtn = el('button', { class: 'koopo-stories__mute', type: 'button', style: 'background:none;border:none;color:#fff;font-size:20px;cursor:pointer;padding:0 10px;opacity:0.7;', title: 'Toggle sound' });
      muteBtn.textContent = '🔇';
      reportBtn = el('button', { class: 'koopo-stories__report', type: 'button', style: 'background:none;border:none;color:#fff;font-size:20px;cursor:pointer;padding:0 10px;opacity:0.7;', title: 'Report this story' });
      reportBtn.textContent = '⚠';
      closeBtn = el('button', { class: 'koopo-stories__close', type: 'button' }, []);
      closeBtn.textContent = '×';
      const header = el('div', { class: 'koopo-stories__header' }, [headerAvatarLink, headerName, statsWrap, muteBtn, reportBtn, closeBtn]);

      stage = el('div', { class: 'koopo-stories__stage' });
      tapPrev = el('div', { class: 'koopo-stories__tap koopo-stories__tap--prev' });
      tapNext = el('div', { class: 'koopo-stories__tap koopo-stories__tap--next' });
      stage.appendChild(tapPrev);
      stage.appendChild(tapNext);

      // Bottom bar with reaction and reply buttons
      reactionBtn = el('button', {
        class: 'koopo-stories__action-btn',
        style: 'background:none;border:none;color:#fff;font-size:24px;cursor:pointer;padding:8px 16px;',
        type: 'button'
      });
      reactionBtn.textContent = '❤️';

      replyBtn = el('button', {
        class: 'koopo-stories__action-btn',
        style: 'background:none;border:none;color:#fff;font-size:16px;cursor:pointer;padding:8px 16px;',
        type: 'button'
      });
      replyBtn.textContent = '💬 Reply';

      bottomBar = el('div', {
        class: 'koopo-stories__viewer-bottom',
        style: 'padding:12px;display:flex;gap:12px;justify-content:center;align-items:center;'
      }, [reactionBtn, replyBtn]);

      const top = el('div', { class: 'koopo-stories__viewer-top' }, [barsWrap, header]);
      root = el('div', { class: 'koopo-stories__viewer', role: 'dialog', 'aria-modal': 'true' }, [top, stage, bottomBar]);
      document.body.appendChild(root);

      closeBtn.addEventListener('click', close);
      tapPrev.addEventListener('click', () => prev());
      tapNext.addEventListener('click', () => next());

      // Mute button - toggle audio for videos
      muteBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        isMuted = !isMuted;
        muteBtn.textContent = isMuted ? '🔇' : '🔊';
        muteBtn.title = isMuted ? 'Unmute' : 'Mute';

        // Update current video if playing
        const currentVideo = stage.querySelector('video.koopo-stories__media');
        if (currentVideo) {
          currentVideo.muted = isMuted;
        }
      });

      // Report button - show report modal
      reportBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        if (!story) return;
        showReportModal(story.story_id, story.author);
      });

      // Reaction button - show emoji picker
      reactionBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        if (!story) return;
        showReactionPicker(story.story_id);
      });

      // Reply button - show reply modal
      replyBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        if (!story) return;
        showReplyModal(story.story_id, story.author);
      });

      // Hold to pause (mouse/touch)
      const pauseOn = () => { paused = true; };
      const resumeOn = () => { paused = false; startTs = performance.now() - currentProgress() * duration; loop(); };
      root.addEventListener('mousedown', pauseOn);
      root.addEventListener('mouseup', resumeOn);
      root.addEventListener('touchstart', pauseOn, { passive: true });
      root.addEventListener('touchend', resumeOn);

      document.addEventListener('keydown', (e) => {
        if (!root.classList.contains('is-open')) return;
        if (e.key === 'Escape') close();
        if (e.key === 'ArrowLeft') prev();
        if (e.key === 'ArrowRight') next();
      });
    }

    function open(storyData, storiesList = [], storyIdx = 0) {
      ensure();
      story = storyData;
      allStories = storiesList;
      currentStoryIndex = storyIdx;
      itemIndex = 0;

      headerAvatar.src = story.author?.avatar || '';
      headerName.textContent = story.author?.name || '';

      // Set profile URL and make clickable
      const profileUrl = story.author?.profile_url || '';
      if (profileUrl) {
        headerAvatarLink.href = profileUrl;
        headerAvatarLink.target = '_blank';
        headerAvatarLink.style.cursor = 'pointer';
      } else {
        headerAvatarLink.href = '#';
        headerAvatarLink.removeAttribute('target');
        headerAvatarLink.style.cursor = 'default';
        headerAvatarLink.onclick = (e) => e.preventDefault();
      }

      // Display analytics (views and reactions)
      const analytics = story.analytics || {};
      const views = analytics.view_count || 0;
      const reactions = analytics.reaction_count || 0;
      const currentUserId = window.KoopoStories?.me || 0;
      const isOwnStory = currentUserId === story.author?.id;

      // Show view count (only to story author)
      if (views > 0 && isOwnStory) {
        viewCount.textContent = `👁 ${views}`;
        viewCount.style.display = 'block';
        viewCount.onclick = () => showViewerList(story.story_id);
      } else {
        viewCount.style.display = 'none';
      }

      // Show reaction count (visible to everyone if > 0)
      if (reactions > 0) {
        reactionCount.textContent = `❤️ ${reactions}`;
        reactionCount.style.display = 'block';
      } else {
        reactionCount.style.display = 'none';
      }

      // Hide reaction/reply buttons for own stories
      if (isOwnStory) {
        bottomBar.style.display = 'none';
        reportBtn.style.display = 'none';
      } else {
        bottomBar.style.display = 'flex';
        reportBtn.style.display = 'block';
      }

      buildBars(story.items?.length || 0);

      root.classList.add('is-open');
      document.documentElement.style.overflow = 'hidden';
      document.body.style.overflow = 'hidden';

      playItem(0);
    }

    function close() {
      if (!root) return;
      root.classList.remove('is-open');
      document.documentElement.style.overflow = '';
      document.body.style.overflow = '';
      cancel();
      story = null;
    }

    function cancel() {
      if (raf) cancelAnimationFrame(raf);
      raf = null;
    }

    function buildBars(n) {
      barsWrap.innerHTML = '';
      for (let i=0;i<n;i++) {
        const fill = el('i');
        const bar = el('div', { class: 'koopo-stories__bar' }, [fill]);
        barsWrap.appendChild(bar);
      }
    }

    function setBar(i, pct) {
      const bar = barsWrap.children[i];
      if (!bar) return;
      const fill = bar.querySelector('i');
      if (fill) fill.style.width = `${Math.max(0, Math.min(100, pct))}%`;
    }

    function currentProgress() {
      if (!duration) return 0;
      return Math.max(0, Math.min(1, (performance.now() - startTs) / duration));
    }

    async function markSeen(itemId) {
      try { await apiPost(`${API_BASE}/items/${itemId}/seen`, null); } catch(e) {}
    }

    function playItem(i) {
      cancel();
      itemIndex = i;
      const items = story.items || [];
      if (!items[itemIndex]) { close(); return; }

      // Fill previous bars
      for (let b=0;b<items.length;b++) setBar(b, b < itemIndex ? 100 : 0);

      const item = items[itemIndex];
      stage.querySelectorAll('.koopo-stories__media').forEach(n => n.remove());

      if (item.type === 'video') {
        const vid = document.createElement('video');
        vid.className = 'koopo-stories__media';
        vid.src = item.src;
        vid.playsInline = true;
        vid.muted = isMuted;
        vid.autoplay = true;
        vid.controls = false;
        vid.addEventListener('loadedmetadata', () => {
          duration = (vid.duration && isFinite(vid.duration)) ? vid.duration * 1000 : 8000;
          startTs = performance.now();
          loop();
        });
        vid.addEventListener('ended', () => next());
        stage.appendChild(vid);

        // Show mute button for videos
        muteBtn.style.display = 'block';

        vid.play().catch(()=>{});
      } else {
        const img = document.createElement('img');
        img.className = 'koopo-stories__media';
        img.src = item.src;
        stage.appendChild(img);

        // Hide mute button for images
        muteBtn.style.display = 'none';

        duration = item.duration_ms || 5000;
        startTs = performance.now();
        loop();
      }

      // mark seen once
      if (item.item_id) markSeen(item.item_id);
    }

    function loop() {
      cancel();
      const items = story.items || [];
      const item = items[itemIndex];
      if (!item) return;

      if (!paused) {
        const pct = currentProgress() * 100;
        setBar(itemIndex, pct);
        if (pct >= 100) { next(); return; }
      }
      raf = requestAnimationFrame(loop);
    }

    async function next() {
      const items = story.items || [];
      if (itemIndex + 1 < items.length) {
        // More items in current story
        playItem(itemIndex + 1);
      } else if (allStories.length > 0 && currentStoryIndex + 1 < allStories.length) {
        // Current story finished, load next user's story
        const nextStoryData = allStories[currentStoryIndex + 1];
        if (nextStoryData && nextStoryData.story_id) {
          try {
            const nextStory = await apiGet(`${API_BASE}/${nextStoryData.story_id}`);
            open(nextStory, allStories, currentStoryIndex + 1);

            // Update ring to mark as seen
            const bubble = document.querySelector(`.koopo-stories__bubble[data-story-id="${nextStoryData.story_id}"]`);
            if (bubble) bubble.setAttribute('data-seen', '1');
          } catch(e) {
            console.error('Failed to load next story:', e);
            close();
          }
        } else {
          close();
        }
      } else {
        // No more stories
        close();
      }
    }

    function prev() {
      if (itemIndex - 1 >= 0) playItem(itemIndex - 1);
      else playItem(0);
    }

    return { open, close };
  })();

  async function loadTray(container) {
    const limit = container.getAttribute('data-limit') || '20';
    const scope = container.getAttribute('data-scope') || 'friends';

    const order = container.getAttribute('data-order') || 'unseen_first';
    const showUploader = (container.getAttribute('data-show-uploader') || '1') === '1';
    const showUnseenBadge = (container.getAttribute('data-show-unseen-badge') || '1') === '1';
    const excludeMe = container.getAttribute('data-exclude-me') || '0';
    const data = await apiGet(`${API_BASE}?limit=${encodeURIComponent(limit)}&scope=${encodeURIComponent(scope)}&order=${encodeURIComponent(order)}&exclude_me=${encodeURIComponent(excludeMe)}`);
    const stories = data.stories || [];
    container.innerHTML = '';

    // "Your story" uploader bubble
    if (showUploader) {
      const meBubble = bubble({
      story_id: 0,
      author: { id: window.KoopoStories.me, name: 'Your Story', avatar: window.KoopoStories.meAvatar || '' },
      cover_thumb: '',
      has_unseen: false,
      items_count: 0,
    }, true, showUnseenBadge);
      container.appendChild(meBubble);
    }

    // Store stories list on container for later access
    container._storiesList = stories;
    stories.forEach(s => container.appendChild(bubble(s, false, showUnseenBadge)));
  }

  function bubble(s, isUploader, showUnseenBadge) {
    const seen = s.has_unseen ? '0' : '1';
    const b = el('div', { class: 'koopo-stories__bubble', 'data-story-id': String(s.story_id || 0), 'data-seen': seen });
    const avatar = el('div', { class: 'koopo-stories__avatar' });
    const ring = el('div', { class: 'koopo-stories__ring' });
    const img = el('img', { src: s.author?.avatar || s.cover_thumb || '' });
    avatar.appendChild(ring);
    avatar.appendChild(img);

    const name = el('div', { class: 'koopo-stories__name' });
    name.textContent = isUploader ? 'Your Story' : (s.author?.name || 'Story');

    if (!isUploader && showUnseenBadge && s.has_unseen && (s.unseen_count || 0) > 0) {
      const badge = el('div', { class: 'koopo-stories__badge' });
      badge.textContent = String(s.unseen_count);
      avatar.appendChild(badge);
    }

    // Privacy indicator for own stories
    if (isUploader === false && s.author?.id === window.KoopoStories.me && s.privacy) {
      const privacyIcon = el('div', { class: 'koopo-stories__privacy-icon' });
      if (s.privacy === 'close_friends') {
        privacyIcon.innerHTML = '&#128274;'; // lock icon
        privacyIcon.title = 'Close Friends';
      } else if (s.privacy === 'friends') {
        privacyIcon.innerHTML = '&#128100;'; // silhouette icon
        privacyIcon.title = 'Friends Only';
      } else if (s.privacy === 'public') {
        privacyIcon.innerHTML = '&#127758;'; // globe icon
        privacyIcon.title = 'Public';
      }
      avatar.appendChild(privacyIcon);
    }

    b.appendChild(avatar);
    b.appendChild(name);

    if (isUploader) {
      b.addEventListener('click', () => uploader());
    } else {
      b.addEventListener('click', async () => {
        const storyId = b.getAttribute('data-story-id');
        if (!storyId) return;

        // Get all stories from the container
        const container = b.closest('.koopo-stories');
        const allStoriesInTray = container?._storiesList || [];

        // Find the index of the clicked story
        const clickedIndex = allStoriesInTray.findIndex(st => String(st.story_id) === storyId);

        const story = await apiGet(`${API_BASE}/${storyId}`);
        Viewer.open(story, allStoriesInTray, clickedIndex >= 0 ? clickedIndex : 0);
        // update ring locally
        b.setAttribute('data-seen','1');
      });
    }
    return b;
  }

  async function uploader() {
    const input = document.createElement('input');
    input.type = 'file';
    input.accept = 'image/*,video/*';
    input.onchange = async () => {
      if (!input.files || !input.files[0]) return;
      openComposer(input.files[0]);
    };
    input.click();
  }

  function openComposer(file) {
    // Simple preview + confirm composer (MVP+)
    const overlay = el('div', { class: 'koopo-stories__composer' });
    const panel = el('div', { class: 'koopo-stories__composer-panel' });
    const title = el('div', { class: 'koopo-stories__composer-title', html: 'Post a story' });

    const preview = el('div', { class: 'koopo-stories__composer-preview' });
    const url = URL.createObjectURL(file);

    let mediaEl;
    if ((file.type || '').startsWith('video/')) {
      mediaEl = document.createElement('video');
      mediaEl.src = url;
      mediaEl.muted = true;
      mediaEl.playsInline = true;
      mediaEl.controls = true;
      mediaEl.autoplay = true;
    } else {
      mediaEl = document.createElement('img');
      mediaEl.src = url;
      mediaEl.alt = '';
    }
    preview.appendChild(mediaEl);

    // Privacy selector
    const privacyWrap = el('div', { class: 'koopo-stories__composer-privacy' });
    const privacyLabel = el('label', { class: 'koopo-stories__composer-privacy-label' });
    privacyLabel.textContent = 'Who can see this?';
    const privacySelect = el('select', { class: 'koopo-stories__composer-privacy-select' });

    const publicOption = el('option', { value: 'public' });
    publicOption.textContent = 'Public';
    const friendsOption = el('option', { value: 'friends' });
    friendsOption.textContent = 'Friends Only';
    friendsOption.selected = true;
    const closeFriendsOption = el('option', { value: 'close_friends' });
    closeFriendsOption.textContent = 'Close Friends';

    privacySelect.appendChild(publicOption);
    privacySelect.appendChild(friendsOption);
    privacySelect.appendChild(closeFriendsOption);

    privacyWrap.appendChild(privacyLabel);
    privacyWrap.appendChild(privacySelect);

    const actions = el('div', { class: 'koopo-stories__composer-actions' });
    const cancelBtn = el('button', { class: 'koopo-stories__composer-cancel', type: 'button' });
    cancelBtn.textContent = 'Cancel';
    const postBtn = el('button', { class: 'koopo-stories__composer-post', type: 'button' });
    postBtn.textContent = 'Post';
    const status = el('div', { class: 'koopo-stories__composer-status', html: '' });

    actions.appendChild(cancelBtn);
    actions.appendChild(postBtn);

    function close() {
      try { URL.revokeObjectURL(url); } catch(e) {}
      overlay.remove();
    }

    cancelBtn.addEventListener('click', close);
    overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });

    postBtn.addEventListener('click', async () => {
      cancelBtn.disabled = true;
      postBtn.disabled = true;
      status.textContent = 'Uploading...';

      const fd = new FormData();
      fd.append('file', file);
      fd.append('privacy', privacySelect.value);

      try {
        await apiPost(`${API_BASE}`, fd);
        status.textContent = 'Posted!';
        // Refresh all trays/widgets on page
        document.querySelectorAll('.koopo-stories').forEach(c => loadTray(c).catch(()=>{}));
        setTimeout(close, 400);
      } catch(e) {
        status.textContent = e.message || 'Upload failed';
        cancelBtn.disabled = false;
        postBtn.disabled = false;
      }
    });

    panel.appendChild(title);
    panel.appendChild(preview);
    panel.appendChild(privacyWrap);
    panel.appendChild(actions);
    panel.appendChild(status);
    overlay.appendChild(panel);
    document.body.appendChild(overlay);
  }

  // Show reaction picker
  function showReactionPicker(storyId) {
    const reactions = ['❤️', '😂', '😮', '😢', '👏', '🔥'];

    const overlay = el('div', {
      class: 'koopo-stories__composer',
      style: 'z-index:9999999;background:rgba(0,0,0,0.3);'
    });

    const picker = el('div', {
      style: 'background:#fff;border-radius:50px;padding:12px 16px;display:flex;gap:8px;box-shadow:0 4px 20px rgba(0,0,0,0.3);'
    });

    reactions.forEach(emoji => {
      const btn = el('button', {
        style: 'background:none;border:none;font-size:32px;cursor:pointer;padding:8px;transition:transform 0.2s;',
        type: 'button'
      });
      btn.textContent = emoji;
      btn.onmouseover = () => { btn.style.transform = 'scale(1.2)'; };
      btn.onmouseout = () => { btn.style.transform = 'scale(1)'; };
      btn.onclick = async () => {
        overlay.remove();
        try {
          const fd = new FormData();
          fd.append('reaction', emoji);
          await apiPost(`${API_BASE}/${storyId}/reactions`, fd);
          // Update reaction button to show user reacted
          const viewer = document.querySelector('.koopo-stories__viewer');
          if (viewer) {
            const reactionBtn = viewer.querySelector('.koopo-stories__action-btn');
            if (reactionBtn) {
              reactionBtn.textContent = emoji;
              reactionBtn.style.transform = 'scale(1.2)';
              setTimeout(() => { reactionBtn.style.transform = 'scale(1)'; }, 200);
            }
          }
        } catch(e) {
          console.error('Failed to add reaction:', e);
        }
      };
      picker.appendChild(btn);
    });

    overlay.onclick = (e) => { if (e.target === overlay) overlay.remove(); };
    overlay.appendChild(picker);
    document.body.appendChild(overlay);

    // Center the picker
    requestAnimationFrame(() => {
      picker.style.position = 'absolute';
      picker.style.top = '50%';
      picker.style.left = '50%';
      picker.style.transform = 'translate(-50%, -50%)';
    });
  }

  // Show reply modal
  function showReplyModal(storyId, author) {
    const overlay = el('div', { class: 'koopo-stories__composer', style: 'z-index:9999999;' });
    const panel = el('div', { class: 'koopo-stories__composer-panel' });
    const title = el('div', { class: 'koopo-stories__composer-title' });
    title.textContent = `Reply to ${author?.name || 'this story'}`;

    const textarea = el('textarea', {
      style: 'width:100%;min-height:120px;padding:12px;background:#2a2a2a;border:1px solid rgba(255,255,255,0.15);border-radius:8px;color:#fff;font-size:14px;resize:vertical;box-sizing:border-box;',
      placeholder: 'Write a reply...'
    });

    const actions = el('div', { class: 'koopo-stories__composer-actions' });
    const cancelBtn = el('button', { class: 'koopo-stories__composer-cancel' });
    cancelBtn.textContent = 'Cancel';
    const sendBtn = el('button', { class: 'koopo-stories__composer-post' });
    sendBtn.textContent = 'Send';

    const status = el('div', { class: 'koopo-stories__composer-status' });

    const close = () => overlay.remove();

    cancelBtn.addEventListener('click', close);
    overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });

    sendBtn.addEventListener('click', async () => {
      const message = textarea.value.trim();
      if (!message) {
        status.textContent = 'Please write a message';
        return;
      }

      sendBtn.disabled = true;
      cancelBtn.disabled = true;
      status.textContent = 'Sending...';

      try {
        const fd = new FormData();
        fd.append('message', message);
        fd.append('is_dm', '1');
        await apiPost(`${API_BASE}/${storyId}/replies`, fd);
        status.textContent = 'Sent!';
        setTimeout(close, 800);
      } catch(e) {
        status.textContent = e.message || 'Failed to send reply';
        sendBtn.disabled = false;
        cancelBtn.disabled = false;
      }
    });

    actions.appendChild(cancelBtn);
    actions.appendChild(sendBtn);

    panel.appendChild(title);
    panel.appendChild(el('div', { style: 'padding:14px;' }, [textarea]));
    panel.appendChild(actions);
    panel.appendChild(status);
    overlay.appendChild(panel);
    document.body.appendChild(overlay);

    // Focus textarea
    setTimeout(() => textarea.focus(), 100);
  }

  // Show report modal
  function showReportModal(storyId, author) {
    const overlay = el('div', { class: 'koopo-stories__composer', style: 'z-index:9999999;' });
    const panel = el('div', { class: 'koopo-stories__composer-panel' });
    const title = el('div', { class: 'koopo-stories__composer-title' });
    title.textContent = `Report ${author?.name || 'this story'}`;

    const reasons = [
      { value: 'spam', label: 'Spam' },
      { value: 'inappropriate', label: 'Inappropriate content' },
      { value: 'harassment', label: 'Harassment or bullying' },
      { value: 'violence', label: 'Violence or dangerous content' },
      { value: 'hate_speech', label: 'Hate speech' },
      { value: 'false_info', label: 'False information' },
      { value: 'other', label: 'Other' },
    ];

    const selectWrap = el('div', { style: 'padding:14px;' });
    const reasonLabel = el('label', { style: 'display:block;margin-bottom:8px;font-size:14px;font-weight:500;' });
    reasonLabel.textContent = 'Reason for reporting:';

    const reasonSelect = el('select', {
      style: 'width:100%;padding:10px;background:#2a2a2a;border:1px solid rgba(255,255,255,0.15);border-radius:8px;color:#fff;font-size:14px;'
    });

    reasons.forEach(r => {
      const option = el('option', { value: r.value });
      option.textContent = r.label;
      reasonSelect.appendChild(option);
    });

    const textarea = el('textarea', {
      style: 'width:100%;min-height:100px;padding:12px;background:#2a2a2a;border:1px solid rgba(255,255,255,0.15);border-radius:8px;color:#fff;font-size:14px;resize:vertical;box-sizing:border-box;margin-top:12px;',
      placeholder: 'Additional details (optional)...'
    });

    selectWrap.appendChild(reasonLabel);
    selectWrap.appendChild(reasonSelect);
    selectWrap.appendChild(textarea);

    const actions = el('div', { class: 'koopo-stories__composer-actions' });
    const cancelBtn = el('button', { class: 'koopo-stories__composer-cancel' });
    cancelBtn.textContent = 'Cancel';
    const submitBtn = el('button', { class: 'koopo-stories__composer-post' });
    submitBtn.textContent = 'Submit Report';

    const status = el('div', { class: 'koopo-stories__composer-status' });

    const close = () => overlay.remove();

    cancelBtn.addEventListener('click', close);
    overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });

    submitBtn.addEventListener('click', async () => {
      submitBtn.disabled = true;
      cancelBtn.disabled = true;
      status.textContent = 'Submitting report...';

      try {
        const fd = new FormData();
        fd.append('reason', reasonSelect.value);
        fd.append('description', textarea.value.trim());
        await apiPost(`${API_BASE}/${storyId}/report`, fd);
        status.textContent = 'Report submitted. Thank you.';
        setTimeout(close, 1500);
      } catch(e) {
        status.textContent = e.message || 'Failed to submit report';
        submitBtn.disabled = false;
        cancelBtn.disabled = false;
      }
    });

    actions.appendChild(cancelBtn);
    actions.appendChild(submitBtn);

    panel.appendChild(title);
    panel.appendChild(selectWrap);
    panel.appendChild(actions);
    panel.appendChild(status);
    overlay.appendChild(panel);
    document.body.appendChild(overlay);
  }

  // Show viewer list modal
  async function showViewerList(storyId) {
    const overlay = el('div', { class: 'koopo-stories__composer', style: 'z-index:9999999;' });
    const panel = el('div', { class: 'koopo-stories__composer-panel', style: 'max-height:80vh;overflow:hidden;' });
    const title = el('div', { class: 'koopo-stories__composer-title' });
    title.textContent = 'Viewers';

    const listWrap = el('div', { style: 'max-height:60vh;overflow-y:auto;padding:12px 14px;' });
    const loading = el('div', { style: 'text-align:center;padding:20px;' });
    loading.textContent = 'Loading...';
    listWrap.appendChild(loading);

    const closeBtn = el('button', { class: 'koopo-stories__composer-cancel', style: 'margin:12px 14px;width:calc(100% - 28px);' });
    closeBtn.textContent = 'Close';

    const close = () => overlay.remove();
    closeBtn.addEventListener('click', close);
    overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });

    panel.appendChild(title);
    panel.appendChild(listWrap);
    panel.appendChild(closeBtn);
    overlay.appendChild(panel);
    document.body.appendChild(overlay);

    // Fetch viewer list
    try {
      const resp = await apiGet(`${API_BASE}/${storyId}/viewers`);
      listWrap.innerHTML = '';

      if (!resp.viewers || resp.viewers.length === 0) {
        const empty = el('div', { style: 'text-align:center;padding:20px;opacity:0.6;' });
        empty.textContent = 'No views yet';
        listWrap.appendChild(empty);
        return;
      }

      resp.viewers.forEach(viewer => {
        const row = el('div', { style: 'display:flex;align-items:center;gap:12px;padding:8px 0;border-bottom:1px solid rgba(255,255,255,0.1);' });

        const avatar = el('img', {
          src: viewer.avatar,
          style: 'width:40px;height:40px;border-radius:999px;'
        });

        const info = el('div', { style: 'flex:1;' });
        const name = el('div', { style: 'font-weight:500;font-size:14px;' });
        name.textContent = viewer.name;

        const time = el('div', { style: 'font-size:12px;opacity:0.7;margin-top:2px;' });
        const viewDate = new Date(viewer.viewed_at);
        time.textContent = viewDate.toLocaleString();

        info.appendChild(name);
        info.appendChild(time);

        row.appendChild(avatar);
        row.appendChild(info);

        if (viewer.profile_url) {
          row.style.cursor = 'pointer';
          row.onclick = () => window.open(viewer.profile_url, '_blank');
        }

        listWrap.appendChild(row);
      });

      // Show total count
      if (resp.total_count > resp.viewers.length) {
        const more = el('div', { style: 'text-align:center;padding:12px;opacity:0.6;font-size:13px;' });
        more.textContent = `Showing ${resp.viewers.length} of ${resp.total_count} viewers`;
        listWrap.appendChild(more);
      }
    } catch(e) {
      listWrap.innerHTML = '';
      const error = el('div', { style: 'text-align:center;padding:20px;color:#d63638;' });
      error.textContent = 'Failed to load viewers';
      listWrap.appendChild(error);
    }
  }

  function init() {
    const nodes = document.querySelectorAll('.koopo-stories');
    nodes.forEach(n => loadTray(n).catch(()=>{}));
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
