(() => {
  if (!window.KoopoStories) {
    console.error('KoopoStories not initialized - Stories may be disabled or plugin not loaded');
    return;
  }

  const API_BASE = window.KoopoStories.restUrl; // .../koopo/v1/stories
  const NONCE = window.KoopoStories.nonce;

  console.log('Koopo Stories initialized:', {
    restUrl: API_BASE,
    hasNonce: !!NONCE,
    userId: window.KoopoStories.me
  });

  const headers = () => ({
    'X-WP-Nonce': NONCE,
  });

  async function apiGet(url) {
    const res = await fetch(url, { credentials: 'same-origin', headers: headers() });

    if (!res.ok) {
      const statusText = res.statusText || 'Request failed';
      console.error('API GET failed:', url, 'Status:', res.status, statusText);
      throw new Error(`Request failed: ${res.status} ${statusText}`);
    }

    // Check if response has content
    const contentType = res.headers.get('content-type');
    if (!contentType || !contentType.includes('application/json')) {
      console.error('Invalid content-type:', contentType, 'for URL:', url);
      const text = await res.text();
      console.error('Response body:', text.substring(0, 500));
      throw new Error('Server returned non-JSON response');
    }

    const text = await res.text();
    if (!text || text.trim() === '') {
      console.error('Empty response from:', url);
      throw new Error('Empty response from server');
    }

    try {
      return JSON.parse(text);
    } catch (e) {
      console.error('JSON parse error:', e, 'Response:', text.substring(0, 500));
      throw new Error('Invalid JSON response from server');
    }
  }

  async function apiPost(url, body) {
    const isFormData = body instanceof FormData;
    const fetchHeaders = isFormData ? headers() : { ...headers(), 'Content-Type': 'application/json' };
    const fetchBody = isFormData ? body : JSON.stringify(body);

    const res = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: fetchHeaders,
      body: fetchBody,
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
    let previousAuthorId = null;  // Track previous author for flip animation

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

      // Add loading overlay
      const loadingOverlay = el('div', { class: 'koopo-stories__loader', style: 'display:none;' });
      const spinner = el('div', { class: 'koopo-stories__spinner' });
      loadingOverlay.appendChild(spinner);
      stage.appendChild(loadingOverlay);

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

      // Set up tap areas with long-press detection for skipping users
      let longPressTimer = null;
      let isLongPress = false;

      // Previous tap/long-press
      tapPrev.addEventListener('mousedown', (e) => {
        e.stopPropagation();
        isLongPress = false;
        longPressTimer = setTimeout(() => {
          isLongPress = true;
          skipToPrevUser();
        }, 500); // 500ms for long press
      });

      tapPrev.addEventListener('mouseup', (e) => {
        e.stopPropagation();
        clearTimeout(longPressTimer);
        if (!isLongPress) {
          prev();
        }
      });

      tapPrev.addEventListener('mouseleave', () => {
        clearTimeout(longPressTimer);
      });

      // Next tap/long-press
      tapNext.addEventListener('mousedown', (e) => {
        e.stopPropagation();
        isLongPress = false;
        longPressTimer = setTimeout(() => {
          isLongPress = true;
          skipToNextUser();
        }, 500); // 500ms for long press
      });

      tapNext.addEventListener('mouseup', (e) => {
        e.stopPropagation();
        clearTimeout(longPressTimer);
        if (!isLongPress) {
          next();
        }
      });

      tapNext.addEventListener('mouseleave', () => {
        clearTimeout(longPressTimer);
      });

      // Touch support for mobile
      tapPrev.addEventListener('touchstart', (e) => {
        e.stopPropagation();
        isLongPress = false;
        longPressTimer = setTimeout(() => {
          isLongPress = true;
          skipToPrevUser();
        }, 500);
      }, { passive: true });

      tapPrev.addEventListener('touchend', (e) => {
        e.stopPropagation();
        clearTimeout(longPressTimer);
        if (!isLongPress) {
          prev();
        }
      });

      tapNext.addEventListener('touchstart', (e) => {
        e.stopPropagation();
        isLongPress = false;
        longPressTimer = setTimeout(() => {
          isLongPress = true;
          skipToNextUser();
        }, 500);
      }, { passive: true });

      tapNext.addEventListener('touchend', (e) => {
        e.stopPropagation();
        clearTimeout(longPressTimer);
        if (!isLongPress) {
          next();
        }
      });

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
        paused = true; // Pause story while reacting
        showReactionPicker(story.story_id);
      });

      // Reply button - show reply modal
      replyBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        if (!story) return;
        paused = true; // Pause story while replying
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

        // Shift + Arrow keys to skip users
        if (e.shiftKey && e.key === 'ArrowLeft') {
          e.preventDefault();
          skipToPrevUser();
        } else if (e.shiftKey && e.key === 'ArrowRight') {
          e.preventDefault();
          skipToNextUser();
        } else if (e.key === 'ArrowLeft') {
          prev();
        } else if (e.key === 'ArrowRight') {
          next();
        }
      });
    }

    function open(storyData, storiesList = [], storyIdx = 0, goToLastItem = false) {
      ensure();

      // Detect if we're switching to a different author (for flip animation)
      const currentAuthorId = story?.author?.id;
      const newAuthorId = storyData?.author?.id;
      const isAuthorChange = previousAuthorId !== null && currentAuthorId !== newAuthorId && root.classList.contains('is-open');

      story = storyData;
      allStories = storiesList;
      currentStoryIndex = storyIdx;

      // Set item index: last item if going backward, otherwise first item
      const totalItems = (story.items || []).length;
      itemIndex = goToLastItem && totalItems > 0 ? totalItems - 1 : 0;
      previousAuthorId = newAuthorId;

      // Apply flip animation if switching authors
      if (isAuthorChange) {
        // Update content immediately so it's ready on the "back" of the flip
        updateStoryContent();

        // Add flip animation class
        root.classList.add('flipping-out');

        // Wait for flip to reach midpoint (200ms), then switch to flip-in
        setTimeout(() => {
          root.classList.remove('flipping-out');
          root.classList.add('flipping-in');

          // After flip-in completes (200ms), start playing
          setTimeout(() => {
            root.classList.remove('flipping-in');
            playItem(itemIndex);
          }, 200);
        }, 200);
      } else {
        updateStoryContent();
        root.classList.add('is-open');
        document.documentElement.style.overflow = 'hidden';
        document.body.style.overflow = 'hidden';
        playItem(itemIndex);
      }
    }

    function updateStoryContent() {
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
      const currentUserId = parseInt(window.KoopoStories?.me || 0, 10);
      const authorId = parseInt(story.author?.id || 0, 10);
      const isOwnStory = currentUserId > 0 && currentUserId === authorId;

      // Show view count (only to story author)
      if (views > 0 && isOwnStory) {
        viewCount.textContent = `👀 ${views}`;
        viewCount.style.display = 'block';
        viewCount.onclick = () => {
          paused = true;
          showViewerList(story.story_id);
        };
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
    }

    function close() {
      if (!root) return;
      // Add closing animation
      root.classList.add('is-closing');
      setTimeout(() => {
        root.classList.remove('is-open');
        root.classList.remove('is-closing');
        document.documentElement.style.overflow = '';
        document.body.style.overflow = '';
        cancel();
        story = null;

        // Refresh all trays when viewer closes to show updated data
        document.querySelectorAll('.koopo-stories').forEach(c => loadTray(c).catch(()=>{}));
      }, 300); // Match CSS transition duration
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

      // Add transition animation
      stage.classList.add('transitioning');
      setTimeout(() => {
        stage.querySelectorAll('.koopo-stories__media').forEach(n => n.remove());
        loadMediaForItem(item);
        stage.classList.remove('transitioning');
      }, 200);
    }

    function loadMediaForItem(item) {

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

      // Render stickers for this item
      renderStickers(item);

      // mark seen once
      if (item.item_id) markSeen(item.item_id);
    }

    // Helper function to resume story after modal closes
    function resumeStory() {
      paused = false;
      startTs = performance.now() - currentProgress() * duration;
      loop();
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

    // Helper function to load all stories for a user (combining multiple stories into one)
    async function loadUserStories(storyData) {
      if (!storyData || !storyData.story_id) return null;

      try {
        // If this user has multiple stories, fetch and combine them
        if (storyData.story_ids && storyData.story_ids.length > 1) {
          const storyPromises = storyData.story_ids.map(sid => apiGet(`${API_BASE}/${sid}`));
          const authorStories = await Promise.all(storyPromises);

          // Combine all items from all stories into one virtual story
          const combinedStory = {
            story_id: storyData.story_id,
            author: storyData.author,
            items: [],
            privacy: storyData.privacy,
            analytics: {
              view_count: 0,
              reaction_count: 0,
            },
          };

          authorStories.forEach(story => {
            if (story.items && Array.isArray(story.items)) {
              combinedStory.items = combinedStory.items.concat(story.items);
            }
            const storyViews = story.analytics?.view_count || 0;
            const storyReactions = story.analytics?.reaction_count || 0;
            combinedStory.analytics.view_count = Math.max(combinedStory.analytics.view_count, storyViews);
            combinedStory.analytics.reaction_count += storyReactions;
          });

          return combinedStory;
        } else {
          // Single story, just fetch it
          return await apiGet(`${API_BASE}/${storyData.story_id}`);
        }
      } catch(e) {
        console.error('Failed to load user stories:', e);
        return null;
      }
    }

    async function next() {
      const items = story.items || [];
      if (itemIndex + 1 < items.length) {
        // More items in current story
        playItem(itemIndex + 1);
      } else if (allStories.length > 0 && currentStoryIndex + 1 < allStories.length) {
        // Current story finished, load next user's story
        const nextStoryData = allStories[currentStoryIndex + 1];
        const nextStory = await loadUserStories(nextStoryData);

        if (nextStory) {
          open(nextStory, allStories, currentStoryIndex + 1);

          // Update ring to mark as seen
          const bubble = document.querySelector(`.koopo-stories__bubble[data-story-id="${nextStoryData.story_id}"]`);
          if (bubble) bubble.setAttribute('data-seen', '1');
        } else {
          close();
        }
      } else {
        // No more stories
        close();
      }
    }

    async function prev() {
      if (itemIndex - 1 >= 0) {
        // Go to previous item in current story
        playItem(itemIndex - 1);
      } else if (allStories.length > 0 && currentStoryIndex - 1 >= 0) {
        // At first item, load previous user's story
        const prevStoryData = allStories[currentStoryIndex - 1];
        const prevStory = await loadUserStories(prevStoryData);

        if (prevStory) {
          open(prevStory, allStories, currentStoryIndex - 1, true); // true = go to last item
        }
      } else {
        // Already at first item of first story, replay current item
        playItem(0);
      }
    }

    // Skip to next user (skip all remaining items in current user's stories)
    async function skipToNextUser() {
      if (allStories.length > 0 && currentStoryIndex + 1 < allStories.length) {
        const nextStoryData = allStories[currentStoryIndex + 1];
        const nextStory = await loadUserStories(nextStoryData);

        if (nextStory) {
          open(nextStory, allStories, currentStoryIndex + 1);

          // Update ring to mark as seen
          const bubble = document.querySelector(`.koopo-stories__bubble[data-story-id="${nextStoryData.story_id}"]`);
          if (bubble) bubble.setAttribute('data-seen', '1');
        } else {
          close();
        }
      } else {
        close();
      }
    }

    // Skip to previous user (skip all items in current user's stories)
    async function skipToPrevUser() {
      if (allStories.length > 0 && currentStoryIndex - 1 >= 0) {
        const prevStoryData = allStories[currentStoryIndex - 1];
        const prevStory = await loadUserStories(prevStoryData);

        if (prevStory) {
          open(prevStory, allStories, currentStoryIndex - 1);
        }
      }
    }

    function renderStickers(item) {
      // Remove existing stickers
      stage.querySelectorAll('.koopo-stories__sticker').forEach(s => s.remove());

      if (!item.stickers || item.stickers.length === 0) return;

      const stickers = item.stickers;
      stickers.forEach(sticker => {
        const stickerEl = createStickerElement(sticker);
        if (stickerEl) {
          stage.appendChild(stickerEl);
        }
      });
    }

    // Create sticker element based on type
    function createStickerElement(sticker) {
    const wrapper = el('div', {
      class: 'koopo-stories__sticker',
      style: `position:absolute;left:${sticker.position.x}%;top:${sticker.position.y}%;transform:translate(-50%,-50%);z-index:10;`
    });

    let content;

    switch (sticker.type) {
      case 'mention':
        content = el('div', {
          class: 'koopo-stories__sticker-mention',
          style: 'background:rgba(0,0,0,0.7);color:#fff;padding:8px 16px;border-radius:20px;font-size:14px;font-weight:500;cursor:pointer border:solid 1px #ffba12;'
        });
        content.textContent = `@${sticker.data.username}`;
        content.onclick = () => {
          if (sticker.data.profile_url) {
            window.open(sticker.data.profile_url, '_blank');
          }
        };
        break;

      case 'link':
        content = el('div', {
          class: 'koopo-stories__sticker-link',
          style: 'background:rgba(255,255,255,0.95);color:#000;padding:12px 16px;border-radius:12px;font-size:13px;cursor:pointer;box-shadow:0 2px 8px rgba(0,0,0,0.2);max-width:200px;'
        });
        const linkIcon = el('div', { style: 'font-size:18px;margin-bottom:4px;' });
        linkIcon.textContent = '🔗';
        const linkTitle = el('div', { style: 'font-weight:600;margin-bottom:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;' });
        linkTitle.textContent = sticker.data.title;
        const linkUrl = el('div', { style: 'font-size:11px;opacity:0.7;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;' });
        linkUrl.textContent = sticker.data.url;
        content.appendChild(linkIcon);
        content.appendChild(linkTitle);
        content.appendChild(linkUrl);
        content.onclick = () => window.open(sticker.data.url, '_blank');
        break;

      case 'location':
        content = el('div', {
          class: 'koopo-stories__sticker-location',
          style: 'background:rgba(0,0,0,0.7);color:#fff;padding:10px 14px;border-radius:16px;font-size:13px;cursor:pointer;'
        });
        const locIcon = el('span', { style: 'margin-right:6px;font-size:16px;' });
        locIcon.textContent = '📍';
        const locName = el('span', { style: 'font-weight:500;' });
        locName.textContent = sticker.data.name;
        content.appendChild(locIcon);
        content.appendChild(locName);
        if (sticker.data.lat && sticker.data.lng) {
          content.onclick = () => {
            window.open(`https://maps.google.com/?q=${sticker.data.lat},${sticker.data.lng}`, '_blank');
          };
        }
        break;

      case 'poll':
        content = createPollSticker(sticker);
        break;

      default:
        return null;
    }

    if (content) {
      wrapper.appendChild(content);
      return wrapper;
    }

    return null;
  }

  // Create interactive poll sticker
  function createPollSticker(sticker) {
    const pollData = sticker.data;

    const pollContainer = el('div', {
      class: 'koopo-stories__sticker-poll',
      style: 'background:rgba(255,255,255,0.95);color:#000;padding:16px;border-radius:16px;min-width:250px;max-width:300px;box-shadow:0 4px 12px rgba(0,0,0,0.3);'
    });

    // Question
    const question = el('div', {
      style: 'font-weight:600;font-size:15px;margin-bottom:12px;'
    });
    question.textContent = pollData.question;
    pollContainer.appendChild(question);

    // Calculate total votes
    const totalVotes = pollData.options.reduce((sum, opt) => sum + (opt.votes || 0), 0);

    // Options
    pollData.options.forEach((option, idx) => {
      const votes = option.votes || 0;
      const percentage = totalVotes > 0 ? Math.round((votes / totalVotes) * 100) : 0;

      const optionEl = el('div', {
        class: 'koopo-stories__poll-option',
        style: 'position:relative;background:#f0f0f0;border-radius:8px;padding:10px 12px;margin-bottom:8px;cursor:pointer;overflow:hidden;'
      });

      // Progress bar
      const progressBar = el('div', {
        style: `position:absolute;top:0;left:0;bottom:0;width:${percentage}%;background:rgba(0,123,255,0.2);transition:width 0.3s;z-index:0;border-radius:8px;`
      });
      optionEl.appendChild(progressBar);

      // Option content
      const optionContent = el('div', { style: 'position:relative;z-index:1;display:flex;justify-content:space-between;align-items:center;' });
      const optionText = el('span', { style: 'font-size:14px;font-weight:500;' });
      optionText.textContent = option.text;
      const optionVotes = el('span', { style: 'font-size:12px;opacity:0.7;font-weight:600;' });
      optionVotes.textContent = `${percentage}%`;
      optionContent.appendChild(optionText);
      optionContent.appendChild(optionVotes);
      optionEl.appendChild(optionContent);

      // Vote handler
      optionEl.onclick = async (e) => {
        e.stopPropagation();
        try {
          const fd = new FormData();
          fd.append('option_index', String(idx));
          await apiPost(`${API_BASE.replace('/stories', '')}/stickers/${sticker.id}/vote`, fd);

          // Update votes locally
          pollData.options[idx].votes = (pollData.options[idx].votes || 0) + 1;

          // Re-render poll
          const parent = pollContainer.parentElement;
          if (parent) {
            parent.removeChild(pollContainer);
            const newPoll = createPollSticker(sticker);
            parent.appendChild(newPoll);
          }
        } catch (err) {
          console.error('Failed to vote:', err);
        }
      };

      pollContainer.appendChild(optionEl);
    });

    // Total votes footer
    if (totalVotes > 0) {
      const footer = el('div', {
        style: 'font-size:12px;opacity:0.6;text-align:center;margin-top:8px;'
      });
      footer.textContent = `${totalVotes} vote${totalVotes !== 1 ? 's' : ''}`;
      pollContainer.appendChild(footer);
    }

    return pollContainer;
  }

    return { open, close, resumeStory };
  })();

  // Global sticker element creator for composer preview
  function createStickerElement(sticker) {
    const wrapper = el('div', {
      class: 'koopo-stories__sticker',
      style: `position:absolute;left:${sticker.position.x}%;top:${sticker.position.y}%;transform:translate(-50%,-50%);z-index:10;`
    });

    let content;

    switch (sticker.type) {
      case 'mention':
        content = el('div', {
          class: 'koopo-stories__sticker-mention',
          style: 'background:rgba(0,0,0,0.7);color:#fff;padding:8px 16px;border-radius:20px;font-size:14px;font-weight:500;cursor:pointer;'
        });
        content.textContent = `@${sticker.data.username}`;
        if (sticker.data.profile_url) {
          content.onclick = () => window.open(sticker.data.profile_url, '_blank');
        }
        break;

      case 'link':
        content = el('div', {
          class: 'koopo-stories__sticker-link',
          style: 'background:rgba(255,255,255,0.95);color:#000;padding:12px 16px;border-radius:12px;font-size:13px;cursor:pointer;box-shadow:0 2px 8px rgba(0,0,0,0.2);max-width:200px;'
        });
        const linkIcon = el('div', { style: 'font-size:18px;margin-bottom:4px;' });
        linkIcon.textContent = '🔗';
        const linkTitle = el('div', { style: 'font-weight:600;margin-bottom:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;' });
        linkTitle.textContent = sticker.data.title;
        const linkUrl = el('div', { style: 'font-size:11px;opacity:0.7;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;' });
        linkUrl.textContent = sticker.data.url;
        content.appendChild(linkIcon);
        content.appendChild(linkTitle);
        content.appendChild(linkUrl);
        content.onclick = () => window.open(sticker.data.url, '_blank');
        break;

      case 'location':
        content = el('div', {
          class: 'koopo-stories__sticker-location',
          style: 'background:rgba(0,0,0,0.7);color:#fff;padding:10px 14px;border-radius:16px;font-size:13px;cursor:pointer;'
        });
        const locIcon = el('span', { style: 'margin-right:6px;font-size:16px;' });
        locIcon.textContent = '📍';
        const locName = el('span', { style: 'font-weight:500;' });
        locName.textContent = sticker.data.name;
        content.appendChild(locIcon);
        content.appendChild(locName);
        if (sticker.data.lat && sticker.data.lng) {
          content.onclick = () => {
            window.open(`https://maps.google.com/?q=${sticker.data.lat},${sticker.data.lng}`, '_blank');
          };
        }
        break;

      case 'poll':
        content = el('div', {
          class: 'koopo-stories__sticker-poll',
          style: 'background:rgba(255,255,255,0.95);color:#000;padding:16px;border-radius:16px;min-width:250px;max-width:300px;box-shadow:0 4px 12px rgba(0,0,0,0.3);'
        });
        const pollQuestion = el('div', { style: 'font-weight:600;font-size:15px;margin-bottom:12px;' });
        pollQuestion.textContent = sticker.data.question;
        content.appendChild(pollQuestion);

        if (sticker.data.options && Array.isArray(sticker.data.options)) {
          sticker.data.options.forEach((option) => {
            const optionEl = el('div', {
              style: 'background:#f0f0f0;border-radius:8px;padding:10px 12px;margin-bottom:8px;'
            });
            const optionText = el('span', { style: 'font-size:14px;font-weight:500;' });
            optionText.textContent = option.text;
            optionEl.appendChild(optionText);
            content.appendChild(optionEl);
          });
        }
        break;

      default:
        return null;
    }

    if (content) {
      wrapper.appendChild(content);
      return wrapper;
    }

    return null;
  }

  async function loadTray(container) {
    const limit = container.getAttribute('data-limit') || '20';
    const scope = container.getAttribute('data-scope') || 'friends';

    const order = container.getAttribute('data-order') || 'unseen_first';
    const showUploader = (container.getAttribute('data-show-uploader') || '1') === '1';
    const showUnseenBadge = (container.getAttribute('data-show-unseen-badge') || '1') === '1';
    const excludeMe = container.getAttribute('data-exclude-me') || '0';

    // Show loading spinner
    container.innerHTML = '<div class="koopo-stories__loader"><div class="koopo-stories__spinner"></div></div>';

    try {
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

      // Set up auto-refresh if not already set
      if (!container._refreshInterval) {
        container._refreshInterval = setInterval(() => {
          // Silently refresh without showing loader
          apiGet(`${API_BASE}?limit=${encodeURIComponent(limit)}&scope=${encodeURIComponent(scope)}&order=${encodeURIComponent(order)}&exclude_me=${encodeURIComponent(excludeMe)}`)
            .then(data => {
              const freshStories = data.stories || [];
              container.innerHTML = '';

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

              container._storiesList = freshStories;
              freshStories.forEach(s => container.appendChild(bubble(s, false, showUnseenBadge)));
            })
            .catch(err => console.error('Auto-refresh failed:', err));
        }, 30000); // Refresh every 30 seconds
      }
    } catch (err) {
      console.error('Failed to load stories:', err);
      container.innerHTML = '<div style="padding:20px;text-align:center;color:#999;">Failed to load stories</div>';
    }
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

    // Show badge with items count if user has stories
    if (!isUploader && (s.items_count || 0) >= 1) {
      const badge = el('div', { class: 'koopo-stories__badge' });
      badge.textContent = String(s.items_count);
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

        // Show instant loading overlay
        const loadingOverlay = el('div', { class: 'koopo-stories__click-loader with-overlay' });
        const spinner = el('div', { class: 'koopo-stories__spinner' });
        loadingOverlay.appendChild(spinner);
        document.body.appendChild(loadingOverlay);

        try {
          // Get all stories from the container
          const container = b.closest('.koopo-stories');
          const allStoriesInTray = container?._storiesList || [];

          // Find the clicked story data
          const clickedStoryData = allStoriesInTray.find(st => String(st.story_id) === storyId);
          const clickedIndex = allStoriesInTray.findIndex(st => String(st.story_id) === storyId);

          // If this author has multiple stories, fetch and combine them all
          if (clickedStoryData && clickedStoryData.story_ids && clickedStoryData.story_ids.length > 1) {
            try {
              // Fetch all stories for this author
              const storyPromises = clickedStoryData.story_ids.map(sid => apiGet(`${API_BASE}/${sid}`));
              const authorStories = await Promise.all(storyPromises);

              // Combine all items from all stories into one virtual story
              const combinedStory = {
                story_id: clickedStoryData.story_id,
                author: clickedStoryData.author,
                items: [],
                privacy: clickedStoryData.privacy,
                analytics: {
                  view_count: 0,
                  reaction_count: 0,
                },
              };

              // Merge all items from all stories
              authorStories.forEach(story => {
                if (story.items && Array.isArray(story.items)) {
                  combinedStory.items = combinedStory.items.concat(story.items);
                }
                const storyViews = story.analytics?.view_count || 0;
                const storyReactions = story.analytics?.reaction_count || 0;
                combinedStory.analytics.view_count = Math.max(combinedStory.analytics.view_count, storyViews);
                combinedStory.analytics.reaction_count += storyReactions;
              });

              // Sort items by creation date
              combinedStory.items.sort((a, b) => {
                return new Date(a.created_at) - new Date(b.created_at);
              });

              Viewer.open(combinedStory, allStoriesInTray, clickedIndex >= 0 ? clickedIndex : 0);
            } catch (err) {
              console.error('Failed to load author stories:', err);
              // Fallback to single story
              const story = await apiGet(`${API_BASE}/${storyId}`);
              Viewer.open(story, allStoriesInTray, clickedIndex >= 0 ? clickedIndex : 0);
            }
          } else {
            // Single story, load normally
            const story = await apiGet(`${API_BASE}/${storyId}`);
            Viewer.open(story, allStoriesInTray, clickedIndex >= 0 ? clickedIndex : 0);
          }

          // update ring locally
          b.setAttribute('data-seen','1');
        } finally {
          // Remove loading overlay
          loadingOverlay.remove();
        }
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

    const preview = el('div', {
      class: 'koopo-stories__composer-preview',
      style: 'position:relative;'
    });
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

    // Sticker toolbar
    const stickerToolbar = el('div', {
      class: 'koopo-stories__composer-toolbar',
      style: 'display:flex;gap:8px;padding:12px;background:rgba(0,0,0,0.05);border-radius:8px;margin:12px 0;'
    });

    const stickerButtons = [
      { icon: '@', label: 'Mention', type: 'mention' },
      { icon: '🔗', label: 'Link', type: 'link' },
      { icon: '📍', label: 'Location', type: 'location' },
      { icon: '📊', label: 'Poll', type: 'poll' }
    ];

    // Array to store stickers to be added
    const pendingStickers = [];

    stickerButtons.forEach(btn => {
      const button = el('button', {
        class: 'koopo-stories__composer-sticker-btn',
        type: 'button',
        style: 'flex:1;padding:8px;background:#fff;border:1px solid #ddd;border-radius:6px;cursor:pointer;font-size:12px;display:flex;flex-direction:column;align-items:center;gap:4px;transition:background 0.2s;'
      });

      const icon = el('span', { style: 'font-size:20px;' });
      icon.textContent = btn.icon;
      const label = el('span', { style: 'font-size:11px;color:#666;' });
      label.textContent = btn.label;

      button.appendChild(icon);
      button.appendChild(label);

      button.onclick = () => openStickerModal(btn.type, pendingStickers, preview);
      button.onmouseover = () => { button.style.background = '#f0f0f0'; };
      button.onmouseout = () => { button.style.background = '#fff'; };

      stickerToolbar.appendChild(button);
    });

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
        const response = await apiPost(`${API_BASE}`, fd);

        // If stickers were added, attach them to the story
        if (pendingStickers.length > 0 && response.story_id && response.item_id) {
          status.textContent = 'Adding stickers...';

          for (const sticker of pendingStickers) {
            try {
              // Send as JSON payload instead of FormData
              const stickerPayload = {
                type: sticker.type,
                data: sticker.data,
                position_x: sticker.position.x,
                position_y: sticker.position.y,
              };

              await apiPost(`${API_BASE}/${response.story_id}/items/${response.item_id}/stickers`, stickerPayload);
            } catch (stickerErr) {
              console.error('Failed to add sticker:', stickerErr);
              // Continue with other stickers even if one fails
            }
          }
        }

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
    panel.appendChild(stickerToolbar);
    panel.appendChild(privacyWrap);
    panel.appendChild(actions);
    panel.appendChild(status);
    overlay.appendChild(panel);
    document.body.appendChild(overlay);
  }

  // Open modal to add a sticker
  function openStickerModal(type, pendingStickers, preview) {
    const modalOverlay = el('div', {
      class: 'koopo-stories__composer',
      style: 'z-index:9999999;'
    });

    const modalPanel = el('div', {
      class: 'koopo-stories__composer-panel',
      style: 'max-width:400px;'
    });

    const modalTitle = el('div', { class: 'koopo-stories__composer-title' });
    modalTitle.textContent = `Add ${type.charAt(0).toUpperCase() + type.slice(1)}`;

    const form = el('div', { style: 'padding:16px;' });

    let inputFields = [];

    // Create form fields based on sticker type
    if (type === 'mention') {
      const inputWrapper = el('div', { style: 'position:relative;' });

      const input = el('input', {
        type: 'text',
        placeholder: 'Enter username...',
        style: 'width:100%;padding:10px;border:1px solid #ddd;border-radius:6px;font-size:14px;'
      });

      const dropdown = el('div', {
        class: 'koopo-stories__mention-dropdown',
        style: 'display:none;position:absolute;top:100%;left:0;right:0;background:#fff;border:1px solid #ddd;border-top:none;border-radius:0 0 6px 6px;max-height:200px;overflow-y:auto;z-index:1000;box-shadow:0 4px 8px rgba(0,0,0,0.1);'
      });

      // Add autocomplete functionality
      let debounceTimer;
      input.oninput = async () => {
        const query = input.value.trim();
        clearTimeout(debounceTimer);

        if (query.length < 2) {
          dropdown.style.display = 'none';
          return;
        }

        debounceTimer = setTimeout(async () => {
          try {
            // Fetch member suggestions from BuddyBoss/BuddyPress
            const response = await fetch(`${window.location.origin}/wp-json/buddyboss/v1/members?search=${encodeURIComponent(query)}&per_page=10`, {
              headers: { 'X-WP-Nonce': window.KoopoStories?.nonce || '' }
            });

            if (!response.ok) throw new Error('Failed to fetch members');

            const data = await response.json();
            dropdown.innerHTML = '';

            if (data && data.length > 0) {
              data.forEach(member => {
                const item = el('div', {
                  style: 'padding:10px 12px;cursor:pointer;display:flex;align-items:center;gap:8px;transition:background 0.2s;'
                });

                // Avatar
                if (member.avatar_urls && member.avatar_urls.thumb) {
                  const avatar = el('img', {
                    src: member.avatar_urls.thumb,
                    style: 'width:32px;height:32px;border-radius:50%;'
                  });
                  item.appendChild(avatar);
                }

                // Name and username
                const textContainer = el('div', { style: 'flex:1;' });
                const name = el('div', { style: 'font-weight:500;font-size:14px;' });
                name.textContent = member.name || member.user_login;
                const username = el('div', { style: 'font-size:12px;color:#666;' });
                username.textContent = '@' + (member.user_login || member.mention_name || '');
                textContainer.appendChild(name);
                textContainer.appendChild(username);
                item.appendChild(textContainer);

                item.onmouseover = () => { item.style.background = '#f0f0f0'; };
                item.onmouseout = () => { item.style.background = 'transparent'; };
                item.onclick = () => {
                  input.value = member.user_login || member.mention_name || member.name;
                  dropdown.style.display = 'none';
                };

                dropdown.appendChild(item);
              });

              dropdown.style.display = 'block';
            } else {
              dropdown.style.display = 'none';
            }
          } catch (err) {
            console.error('Failed to fetch members:', err);
            dropdown.style.display = 'none';
          }
        }, 300);
      };

      // Close dropdown when clicking outside
      document.addEventListener('click', (e) => {
        if (!inputWrapper.contains(e.target)) {
          dropdown.style.display = 'none';
        }
      });

      inputWrapper.appendChild(input);
      inputWrapper.appendChild(dropdown);
      form.appendChild(inputWrapper);
      inputFields.push({ key: 'username', el: input });
    } else if (type === 'link') {
      const urlInput = el('input', {
        type: 'url',
        placeholder: 'Enter URL...',
        style: 'width:100%;padding:10px;border:1px solid #ddd;border-radius:6px;font-size:14px;margin-bottom:10px;'
      });
      const titleInput = el('input', {
        type: 'text',
        placeholder: 'Link title (optional)...',
        style: 'width:100%;padding:10px;border:1px solid #ddd;border-radius:6px;font-size:14px;'
      });
      form.appendChild(urlInput);
      form.appendChild(titleInput);
      inputFields.push({ key: 'url', el: urlInput }, { key: 'title', el: titleInput });
    } else if (type === 'location') {
      const nameInput = el('input', {
        type: 'text',
        placeholder: 'Location name...',
        style: 'width:100%;padding:10px;border:1px solid #ddd;border-radius:6px;font-size:14px;margin-bottom:10px;'
      });
      const addressInput = el('input', {
        type: 'text',
        placeholder: 'Address (optional)...',
        style: 'width:100%;padding:10px;border:1px solid #ddd;border-radius:6px;font-size:14px;'
      });
      form.appendChild(nameInput);
      form.appendChild(addressInput);
      inputFields.push({ key: 'name', el: nameInput }, { key: 'address', el: addressInput });
    } else if (type === 'poll') {
      const questionInput = el('input', {
        type: 'text',
        placeholder: 'Poll question...',
        style: 'width:100%;padding:10px;border:1px solid #ddd;border-radius:6px;font-size:14px;margin-bottom:10px;'
      });
      form.appendChild(questionInput);

      const option1 = el('input', {
        type: 'text',
        placeholder: 'Option 1...',
        style: 'width:100%;padding:10px;border:1px solid #ddd;border-radius:6px;font-size:14px;margin-bottom:8px;'
      });
      const option2 = el('input', {
        type: 'text',
        placeholder: 'Option 2...',
        style: 'width:100%;padding:10px;border:1px solid #ddd;border-radius:6px;font-size:14px;margin-bottom:8px;'
      });
      const option3 = el('input', {
        type: 'text',
        placeholder: 'Option 3 (optional)...',
        style: 'width:100%;padding:10px;border:1px solid #ddd;border-radius:6px;font-size:14px;margin-bottom:8px;'
      });
      const option4 = el('input', {
        type: 'text',
        placeholder: 'Option 4 (optional)...',
        style: 'width:100%;padding:10px;border:1px solid #ddd;border-radius:6px;font-size:14px;'
      });

      form.appendChild(option1);
      form.appendChild(option2);
      form.appendChild(option3);
      form.appendChild(option4);

      inputFields.push(
        { key: 'question', el: questionInput },
        { key: 'option1', el: option1 },
        { key: 'option2', el: option2 },
        { key: 'option3', el: option3 },
        { key: 'option4', el: option4 }
      );
    }

    const actions = el('div', { class: 'koopo-stories__composer-actions' });
    const cancelBtn = el('button', { class: 'koopo-stories__composer-cancel', type: 'button' });
    cancelBtn.textContent = 'Cancel';
    const addBtn = el('button', { class: 'koopo-stories__composer-post', type: 'button' });
    addBtn.textContent = 'Add Sticker';

    actions.appendChild(cancelBtn);
    actions.appendChild(addBtn);

    const closeModal = () => modalOverlay.remove();

    cancelBtn.onclick = closeModal;
    modalOverlay.onclick = (e) => { if (e.target === modalOverlay) closeModal(); };

    addBtn.onclick = () => {
      const data = {};

      // Collect form data
      inputFields.forEach(field => {
        const value = field.el.value.trim();
        if (value) data[field.key] = value;
      });

      // Validate based on type
      let isValid = false;
      if (type === 'mention' && data.username) {
        isValid = true;
      } else if (type === 'link' && data.url) {
        isValid = true;
        if (!data.title) data.title = data.url;
      } else if (type === 'location' && data.name) {
        isValid = true;
      } else if (type === 'poll' && data.question && data.option1 && data.option2) {
        // Convert poll options to array format
        const options = [data.option1, data.option2];
        if (data.option3) options.push(data.option3);
        if (data.option4) options.push(data.option4);
        data.options = options.map(text => ({ text, votes: 0 }));
        delete data.option1;
        delete data.option2;
        delete data.option3;
        delete data.option4;
        isValid = true;
      }

      if (!isValid) {
        alert('Please fill in required fields');
        return;
      }

      // Add sticker to pending list
      const sticker = {
        type,
        data,
        position: { x: 50, y: 50 } // Default center position
      };

      pendingStickers.push(sticker);

      // Show preview of sticker on the media
      const stickerPreview = createStickerElement(sticker);
      if (stickerPreview) {
        stickerPreview.style.cursor = 'move';
        preview.appendChild(stickerPreview);

        // Make sticker draggable within preview
        makeDraggable(stickerPreview, preview, sticker.position);
      }

      closeModal();
    };

    modalPanel.appendChild(modalTitle);
    modalPanel.appendChild(form);
    modalPanel.appendChild(actions);
    modalOverlay.appendChild(modalPanel);
    document.body.appendChild(modalOverlay);
  }

  // Make sticker draggable within preview
  function makeDraggable(element, container, position) {
    let isDragging = false;
    let startX, startY, initialLeft, initialTop;

    element.onmousedown = (e) => {
      if (e.target.tagName === 'BUTTON' || e.target.tagName === 'A') return;
      isDragging = true;
      startX = e.clientX;
      startY = e.clientY;
      const rect = container.getBoundingClientRect();
      initialLeft = (position.x / 100) * rect.width;
      initialTop = (position.y / 100) * rect.height;
      e.preventDefault();
    };

    document.onmousemove = (e) => {
      if (!isDragging) return;

      const deltaX = e.clientX - startX;
      const deltaY = e.clientY - startY;
      const rect = container.getBoundingClientRect();

      const newLeft = initialLeft + deltaX;
      const newTop = initialTop + deltaY;

      // Update position as percentage
      position.x = Math.max(0, Math.min(100, (newLeft / rect.width) * 100));
      position.y = Math.max(0, Math.min(100, (newTop / rect.height) * 100));

      element.style.left = position.x + '%';
      element.style.top = position.y + '%';
    };

    document.onmouseup = () => {
      isDragging = false;
    };
  }

  // Show reaction picker
  function showReactionPicker(storyId) {
    const reactions = ['❤️', '😂', '😮', '😢', '👏', '🔥', '💩', '🤦🏽‍♂️', '👿', '🤯'];

    const overlay = el('div', {
      class: 'koopo-stories__composer',
      style: 'z-index:9999999;background:rgba(0,0,0,0.3);'
    });

    const picker = el('div', {
      style: 'background:rgba(0,0,0,0.66);border-radius:50px;padding:12px 16px;display:flex;gap:8px;box-shadow:0 4px 20px rgba(0,0,0,0.3);'
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
        Viewer.resumeStory(); // Resume story after closing modal
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

    overlay.onclick = (e) => {
      if (e.target === overlay) {
        overlay.remove();
        Viewer.resumeStory(); // Resume story if modal closed without selecting
      }
    };
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

    const close = () => {
      overlay.remove();
      Viewer.resumeStory(); // Resume story after closing modal
    };

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
    const loading = el('div', { class: 'koopo-stories__loader', style: 'position:relative;padding:40px;' });
    const spinner = el('div', { class: 'koopo-stories__spinner' });
    loading.appendChild(spinner);
    listWrap.appendChild(loading);

    const closeBtn = el('button', { class: 'koopo-stories__composer-cancel', style: 'margin:12px 14px;width:calc(100% - 28px);' });
    closeBtn.textContent = 'Close';

    const close = () => {
      overlay.remove();
      Viewer.resumeStory();
    };
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
