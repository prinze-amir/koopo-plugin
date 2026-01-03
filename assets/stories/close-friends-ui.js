(() => {
  if (!window.KoopoCloseFriends) return;

  const API_BASE = window.KoopoCloseFriends.restUrl;
  const NONCE = window.KoopoCloseFriends.nonce;

  const headers = () => ({
    'X-WP-Nonce': NONCE,
  });

  async function addFriend(friendId) {
    const res = await fetch(`${API_BASE}/${friendId}`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: headers(),
    });

    if (!res.ok) {
      throw new Error('Failed to add friend');
    }

    return res.json();
  }

  async function removeFriend(friendId) {
    const res = await fetch(`${API_BASE}/${friendId}`, {
      method: 'DELETE',
      credentials: 'same-origin',
      headers: headers(),
    });

    if (!res.ok) {
      throw new Error('Failed to remove friend');
    }

    return res.json();
  }

  // Handle toggle button clicks
  document.addEventListener('click', async (e) => {
    const btn = e.target.closest('.koopo-close-friend-toggle');
    if (!btn) return;

    e.preventDefault();

    if (btn.disabled || btn.classList.contains('is-loading')) return;

    const friendId = btn.getAttribute('data-friend-id');
    const action = btn.getAttribute('data-action');

    if (!friendId || !action) return;

    // Set loading state
    btn.classList.add('is-loading');
    btn.disabled = true;
    const originalText = btn.textContent;
    btn.textContent = action === 'add' ? 'Adding...' : 'Removing...';

    try {
      if (action === 'add') {
        await addFriend(friendId);
        // Update UI to reflect added state
        btn.classList.add('is-close');
        btn.setAttribute('data-action', 'remove');
        btn.textContent = 'Remove';

        // Update count
        updateCount(1);
      } else {
        await removeFriend(friendId);
        // Update UI to reflect removed state
        btn.classList.remove('is-close');
        btn.setAttribute('data-action', 'add');
        btn.textContent = 'Add';

        // Update count
        updateCount(-1);
      }
    } catch (err) {
      console.error('Close friends error:', err);
      alert('Failed to update close friends list. Please try again.');
      btn.textContent = originalText;
    } finally {
      btn.classList.remove('is-loading');
      btn.disabled = false;
    }
  });

  function updateCount(delta) {
    const countEl = document.querySelector('.koopo-close-friends-count strong');
    if (countEl) {
      const current = parseInt(countEl.textContent) || 0;
      countEl.textContent = Math.max(0, current + delta);
    }
  }
})();
