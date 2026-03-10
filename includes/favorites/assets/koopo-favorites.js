(function () {
    if (typeof window.KoopoFavoritesData === 'undefined') {
        return;
    }

    var cfg = window.KoopoFavoritesData;
    var state = {
        lists: null,
        postStatus: {},
        dashboardRefreshers: []
    };

    function escapeHtml(str) {
        return String(str || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function api(path, options) {
        options = options || {};

        var method = options.method || 'GET';
        var headers = {
            'X-WP-Nonce': cfg.nonce
        };

        if (method !== 'GET' && method !== 'DELETE') {
            headers['Content-Type'] = 'application/json';
        }

        return fetch(cfg.restBase + path, {
            method: method,
            credentials: 'same-origin',
            headers: headers,
            body: options.body ? JSON.stringify(options.body) : undefined
        }).then(function (res) {
            return res.text().then(function (text) {
                var data = {};
                if (text) {
                    try {
                        data = JSON.parse(text);
                    } catch (e) {
                        data = {};
                    }
                }
                if (!res.ok) {
                    throw new Error((data && data.message) || cfg.i18n.error);
                }
                return data;
            });
        });
    }

    function ensureLoggedIn() {
        if (cfg.isLoggedIn) {
            return true;
        }
        window.location.href = cfg.loginUrl;
        return false;
    }

    function ensureLists() {
        if (!cfg.isLoggedIn) {
            return Promise.resolve([]);
        }

        return api('/lists').then(function (lists) {
            state.lists = Array.isArray(lists) ? lists : [];
            return state.lists;
        });
    }

    function ensurePostStatus(postId) {
        postId = parseInt(postId, 10);
        if (!cfg.isLoggedIn || !postId) {
            return Promise.resolve({
                post_id: postId,
                is_favorited: false,
                list_ids: []
            });
        }

        return api('/post/' + postId + '/status').then(function (status) {
            state.postStatus[String(postId)] = status;
            syncHeartButtons(postId);
            return status;
        });
    }

    function refreshAllDashboards() {
        state.dashboardRefreshers.forEach(function (fn) {
            if (typeof fn === 'function') {
                fn();
            }
        });
    }

    function syncHeartButtons(postId) {
        var status = state.postStatus[String(postId)] || { is_favorited: false };
        document.querySelectorAll('.koopo-favorite-heart[data-post-id="' + postId + '"]').forEach(function (btn) {
            var active = !!status.is_favorited;
            btn.classList.toggle('is-active', active);
            btn.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
    }

    function getOrCreatePicker() {
        var existing = document.querySelector('.koopo-favorites-picker');
        if (existing) {
            return existing;
        }

        var picker = document.createElement('div');
        picker.className = 'koopo-favorites-picker';
        picker.hidden = true;
        picker.innerHTML = '' +
            '<div class="koopo-favorites-picker__overlay" data-action="close"></div>' +
            '<div class="koopo-favorites-picker__panel">' +
                '<button type="button" class="koopo-favorites-picker__close" data-action="close" aria-label="' + escapeHtml(cfg.i18n.cancelButton) + '">×</button>' +
                '<h3>' + escapeHtml(cfg.i18n.pickerTitle) + '</h3>' +
                '<div class="koopo-favorites-picker__lists" data-role="picker-lists"></div>' +
                '<div class="koopo-favorites-picker__create">' +
                    '<input type="text" data-role="new-list-name" placeholder="' + escapeHtml(cfg.i18n.createListPlaceholder) + '" />' +
                    '<button type="button" class="button" data-action="create-list">' + escapeHtml(cfg.i18n.createListButton) + '</button>' +
                '</div>' +
            '</div>';
        document.body.appendChild(picker);

        picker.addEventListener('click', function (e) {
            var actionTarget = e.target.closest('[data-action]');
            if (!actionTarget) {
                return;
            }

            var action = actionTarget.getAttribute('data-action');
            if ('close' === action) {
                closePicker();
                return;
            }

            if ('create-list' === action) {
                var input = picker.querySelector('[data-role="new-list-name"]');
                var name = input ? input.value.trim() : '';
                if (!name) {
                    return;
                }

                api('/lists', {
                    method: 'POST',
                    body: { name: name }
                }).then(function (list) {
                    var postId = parseInt(picker.dataset.postId || '0', 10);
                    input.value = '';
                    if (!postId || !list || !list.id) {
                        return ensureLists();
                    }
                    return api('/lists/' + encodeURIComponent(list.id) + '/items', {
                        method: 'POST',
                        body: { post_id: postId }
                    }).then(function () {
                        return ensureLists();
                    });
                }).then(function () {
                    var currentPostId = parseInt(picker.dataset.postId || '0', 10);
                    if (currentPostId) {
                        return ensurePostStatus(currentPostId);
                    }
                    return null;
                }).then(function () {
                    renderPickerLists();
                    refreshAllDashboards();
                }).catch(function (err) {
                    window.alert(err.message || cfg.i18n.error);
                });
                return;
            }
        });

        picker.addEventListener('change', function (e) {
            var toggle = e.target.closest('[data-action="toggle-list"]');
            if (!toggle) {
                return;
            }
            handleToggleList(toggle);
        });

        return picker;
    }

    function openPicker(postId) {
        if (!ensureLoggedIn()) {
            return;
        }

        postId = parseInt(postId, 10);
        if (!postId) {
            return;
        }

        Promise.all([ensureLists(), ensurePostStatus(postId)]).then(function (result) {
            var picker = getOrCreatePicker();
            picker.dataset.postId = String(postId);
            picker.hidden = false;
            renderPickerLists();
        }).catch(function (err) {
            window.alert(err.message || cfg.i18n.error);
        });
    }

    function closePicker() {
        var picker = getOrCreatePicker();
        picker.hidden = true;
    }

    function renderPickerLists() {
        var picker = getOrCreatePicker();
        var listWrap = picker.querySelector('[data-role="picker-lists"]');
        var postId = parseInt(picker.dataset.postId || '0', 10);
        var selected = [];
        if (postId && state.postStatus[String(postId)] && Array.isArray(state.postStatus[String(postId)].list_ids)) {
            selected = state.postStatus[String(postId)].list_ids;
        }

        if (!state.lists || !state.lists.length) {
            listWrap.innerHTML = '<p class="koopo-favorites-empty">' + escapeHtml(cfg.i18n.noLists) + '</p>';
            return;
        }

        listWrap.innerHTML = state.lists.map(function (list) {
            var checked = selected.indexOf(list.id) !== -1 ? ' checked' : '';
            return '' +
                '<label class="koopo-favorites-picker__list">' +
                    '<input type="checkbox" data-action="toggle-list" value="' + escapeHtml(list.id) + '"' + checked + ' />' +
                    '<span>' + escapeHtml(list.name) + ' (' + Number(list.items_count || 0) + ')</span>' +
                '</label>';
        }).join('');
    }

    function handleToggleList(toggle) {
        var picker = getOrCreatePicker();
        var postId = parseInt(picker.dataset.postId || '0', 10);
        var listId = toggle.value;
        if (!postId || !listId) {
            return;
        }

        toggle.disabled = true;

        var request = toggle.checked
            ? api('/lists/' + encodeURIComponent(listId) + '/items', { method: 'POST', body: { post_id: postId } })
            : api('/lists/' + encodeURIComponent(listId) + '/items/' + postId, { method: 'DELETE' });

        request.then(function () {
            return Promise.all([ensureLists(), ensurePostStatus(postId)]);
        }).then(function () {
            renderPickerLists();
            refreshAllDashboards();
        }).catch(function (err) {
            toggle.checked = !toggle.checked;
            window.alert(err.message || cfg.i18n.error);
        }).finally(function () {
            toggle.disabled = false;
        });
    }

    function renderListCard(list) {
        var itemsHtml = '';
        if (Array.isArray(list.items) && list.items.length) {
            itemsHtml = list.items.map(function (item) {
                return '' +
                    '<li class="koopo-favorites-item">' +
                        '<a href="' + escapeHtml(item.url) + '">' + escapeHtml(item.title) + '</a>' +
                        '<button type="button" class="button-link-delete" data-action="remove-item" data-list-id="' + escapeHtml(list.id) + '" data-post-id="' + Number(item.post_id) + '">×</button>' +
                    '</li>';
            }).join('');
        } else {
            itemsHtml = '<li class="koopo-favorites-empty">' + escapeHtml(cfg.i18n.noItems) + '</li>';
        }

        var shareBtnText = list.is_public ? 'Unshare' : 'Share';
        var isDefault = !!list.is_default;
        var renameButton = isDefault ? '' : '<button type="button" class="button button-small" data-action="rename-list" data-list-id="' + escapeHtml(list.id) + '">Rename</button>';
        var deleteButton = isDefault ? '' : '<button type="button" class="button button-small button-link-delete" data-action="delete-list" data-list-id="' + escapeHtml(list.id) + '">Delete</button>';

        return '' +
            '<article class="koopo-favorites-list" data-list-id="' + escapeHtml(list.id) + '">' +
                '<header class="koopo-favorites-list__header">' +
                    '<h3>' + escapeHtml(list.name) + (isDefault ? ' <span class="koopo-favorites-default-tag">Default</span>' : '') + '</h3>' +
                    '<small>' + Number(list.items_count || 0) + ' items</small>' +
                '</header>' +
                '<div class="koopo-favorites-list__actions">' +
                    renameButton +
                    '<button type="button" class="button button-small" data-action="share-list" data-list-id="' + escapeHtml(list.id) + '">' + escapeHtml(shareBtnText) + '</button>' +
                    '<button type="button" class="button button-small" data-action="post-list" data-list-id="' + escapeHtml(list.id) + '">Post List</button>' +
                    deleteButton +
                '</div>' +
                '<ul class="koopo-favorites-items">' + itemsHtml + '</ul>' +
                (list.is_public && list.share_url ? '<p class="koopo-favorites-share-url"><a href="' + escapeHtml(list.share_url) + '">' + escapeHtml(list.share_url) + '</a></p>' : '') +
            '</article>';
    }

    function initDashboard(app) {
        var listWrap = app.querySelector('[data-role="lists"]');
        var form = app.querySelector('[data-role="create-list-form"]');

        function refresh() {
            ensureLists().then(function (lists) {
                if (!lists.length) {
                    listWrap.innerHTML = '<p class="koopo-favorites-empty">' + escapeHtml(cfg.i18n.noLists) + '</p>';
                    return;
                }
                listWrap.innerHTML = lists.map(renderListCard).join('');
            }).catch(function (err) {
                listWrap.innerHTML = '<p class="koopo-favorites-empty">' + escapeHtml(err.message || cfg.i18n.error) + '</p>';
            });
        }

        state.dashboardRefreshers.push(refresh);

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var input = form.querySelector('input[name="name"]');
            var name = input ? input.value.trim() : '';
            if (!name) {
                return;
            }

            api('/lists', {
                method: 'POST',
                body: { name: name }
            }).then(function () {
                input.value = '';
                refresh();
            }).catch(function (err) {
                window.alert(err.message || cfg.i18n.error);
            });
        });

        app.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-action]');
            if (!btn) {
                return;
            }

            var action = btn.getAttribute('data-action');
            var listId = btn.getAttribute('data-list-id');
            var postId = btn.getAttribute('data-post-id');

            if ('delete-list' === action) {
                if (!window.confirm(cfg.i18n.deleteListConfirm)) {
                    return;
                }
                api('/lists/' + encodeURIComponent(listId), { method: 'DELETE' }).then(function () {
                    refresh();
                }).catch(function (err) {
                    window.alert(err.message || cfg.i18n.error);
                });
                return;
            }

            if ('rename-list' === action) {
                var nextName = window.prompt(cfg.i18n.renamePrompt, '');
                if (!nextName) {
                    return;
                }
                api('/lists/' + encodeURIComponent(listId), {
                    method: 'POST',
                    body: { name: nextName }
                }).then(function () {
                    refresh();
                }).catch(function (err) {
                    window.alert(err.message || cfg.i18n.error);
                });
                return;
            }

            if ('remove-item' === action) {
                if (!window.confirm(cfg.i18n.removeItemConfirm)) {
                    return;
                }
                api('/lists/' + encodeURIComponent(listId) + '/items/' + Number(postId), { method: 'DELETE' }).then(function () {
                    refresh();
                    if (postId) {
                        ensurePostStatus(postId);
                    }
                }).catch(function (err) {
                    window.alert(err.message || cfg.i18n.error);
                });
                return;
            }

            if ('share-list' === action) {
                var list = (state.lists || []).find(function (l) { return l.id === listId; });
                var nextPublic = !(list && list.is_public);
                api('/lists/' + encodeURIComponent(listId) + '/share', {
                    method: 'POST',
                    body: { is_public: nextPublic }
                }).then(function (updated) {
                    refresh();
                    if (updated && updated.share_url && nextPublic) {
                        if (navigator.clipboard && navigator.clipboard.writeText) {
                            navigator.clipboard.writeText(updated.share_url).catch(function () {});
                        }
                        window.alert(cfg.i18n.copySuccess);
                    }
                }).catch(function (err) {
                    window.alert(err.message || cfg.i18n.error);
                });
                return;
            }

            if ('post-list' === action) {
                api('/lists/' + encodeURIComponent(listId) + '/publish', {
                    method: 'POST',
                    body: { status: 'draft' }
                }).then(function (res) {
                    if (res && res.edit_url) {
                        window.open(res.edit_url, '_blank');
                    }
                    window.alert(cfg.i18n.publishSuccess);
                }).catch(function (err) {
                    window.alert(err.message || cfg.i18n.error);
                });
            }
        });

        refresh();
    }

    function initShared(app) {
        var slug = app.getAttribute('data-share-slug');
        var target = app.querySelector('[data-role="shared-list"]');

        if (!slug || !target) {
            return;
        }

        api('/shared/' + encodeURIComponent(slug)).then(function (list) {
            var owner = list.owner && list.owner.display_name ? '<p class="koopo-favorites-shared-owner">By ' + escapeHtml(list.owner.display_name) + '</p>' : '';
            var itemsHtml = '';
            if (Array.isArray(list.items) && list.items.length) {
                itemsHtml = list.items.map(function (item) {
                    return '<li><a href="' + escapeHtml(item.url) + '">' + escapeHtml(item.title) + '</a></li>';
                }).join('');
            } else {
                itemsHtml = '<li>' + escapeHtml(cfg.i18n.noItems) + '</li>';
            }

            target.innerHTML = '' +
                '<article class="koopo-favorites-shared-card">' +
                    '<h3>' + escapeHtml(list.name) + '</h3>' +
                    owner +
                    '<ul>' + itemsHtml + '</ul>' +
                '</article>';
        }).catch(function () {
            target.innerHTML = '<p class="koopo-favorites-empty">Shared list not found.</p>';
        });
    }

    function initHearts() {
        var hearts = document.querySelectorAll('.koopo-favorite-heart[data-post-id]');
        if (!hearts.length) {
            return;
        }

        hearts.forEach(function (btn) {
            var postId = parseInt(btn.getAttribute('data-post-id'), 10);
            if (cfg.isLoggedIn && postId) {
                ensurePostStatus(postId);
            }
        });

        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.koopo-favorite-heart[data-post-id]');
            if (!btn) {
                return;
            }

            e.preventDefault();
            var postId = parseInt(btn.getAttribute('data-post-id'), 10);
            openPicker(postId);
        });
    }

    function init() {
        document.querySelectorAll('.koopo-favorites-app[data-view="dashboard"]').forEach(initDashboard);
        document.querySelectorAll('.koopo-favorites-app[data-view="shared"]').forEach(initShared);
        initHearts();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
