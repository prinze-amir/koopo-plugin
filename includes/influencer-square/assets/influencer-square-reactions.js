(function () {
    function parseCount(value) {
        var number = parseInt(String(value || '0').replace(/[^\d-]/g, ''), 10);
        return Number.isFinite(number) ? number : 0;
    }

    function setState(postId, stats) {
        var wrappers = document.querySelectorAll('.koopo-is-reactions[data-post-id="' + postId + '"]');
        wrappers.forEach(function (wrap) {
            var likeButton = wrap.querySelector('[data-reaction="like"]');
            var dislikeButton = wrap.querySelector('[data-reaction="dislike"]');
            var likeCount = wrap.querySelector('[data-count-for="like"]');
            var dislikeCount = wrap.querySelector('[data-count-for="dislike"]');

            if (likeCount) {
                likeCount.textContent = String(stats.likes || 0);
            }
            if (dislikeCount) {
                dislikeCount.textContent = String(stats.dislikes || 0);
            }

            var current = stats.current_reaction || 'none';
            if (likeButton) {
                likeButton.classList.toggle('is-active', current === 'like');
            }
            if (dislikeButton) {
                dislikeButton.classList.toggle('is-active', current === 'dislike');
            }

            wrap.setAttribute('data-current-reaction', current);
        });
    }

    function setBusy(postId, isBusy) {
        var wrappers = document.querySelectorAll('.koopo-is-reactions[data-post-id="' + postId + '"]');
        wrappers.forEach(function (wrap) {
            wrap.querySelectorAll('.koopo-is-btn').forEach(function (button) {
                button.disabled = isBusy;
            });
        });
    }

    function request(url, payload) {
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': KoopoInfluencerSquare.nonce
            },
            body: JSON.stringify(payload)
        }).then(function (response) {
            return response.json().then(function (json) {
                if (!response.ok) {
                    throw json;
                }
                return json;
            });
        });
    }

    function onReactionClick(event) {
        var button = event.target.closest('.koopo-is-btn');
        if (!button) {
            return;
        }

        var wrap = button.closest('.koopo-is-reactions');
        if (!wrap) {
            return;
        }

        var postId = parseInt(wrap.getAttribute('data-post-id'), 10);
        if (!postId) {
            return;
        }

        if (!KoopoInfluencerSquare.isLoggedIn) {
            window.location.href = KoopoInfluencerSquare.loginUrl;
            return;
        }

        var selected = button.getAttribute('data-reaction');
        var current = wrap.getAttribute('data-current-reaction') || 'none';
        var nextReaction = current === selected ? 'none' : selected;

        setBusy(postId, true);
        request(KoopoInfluencerSquare.restBase + '/reaction', {
            post_id: postId,
            reaction: nextReaction
        }).then(function (stats) {
            setState(postId, stats);
        }).catch(function () {
            window.alert(KoopoInfluencerSquare.messages.error);
        }).finally(function () {
            setBusy(postId, false);
        });
    }

    function hydrateCountsFromMarkup() {
        var wrappers = document.querySelectorAll('.koopo-is-reactions');
        wrappers.forEach(function (wrap) {
            var postId = parseInt(wrap.getAttribute('data-post-id'), 10);
            if (!postId) {
                return;
            }

            var likeCount = parseCount((wrap.querySelector('[data-count-for="like"]') || {}).textContent);
            var dislikeCount = parseCount((wrap.querySelector('[data-count-for="dislike"]') || {}).textContent);
            var current = wrap.getAttribute('data-current-reaction') || 'none';

            setState(postId, {
                likes: likeCount,
                dislikes: dislikeCount,
                current_reaction: current
            });
        });
    }

    document.addEventListener('click', onReactionClick);
    document.addEventListener('DOMContentLoaded', hydrateCountsFromMarkup);
})();

