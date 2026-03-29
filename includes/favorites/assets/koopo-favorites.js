(function () {
    if (typeof window.KoopoFavoritesData === 'undefined') {
        return;
    }

    var cfg = window.KoopoFavoritesData;
    var state = {
        lists: null,
        postStatus: {},
        dashboardRefreshers: [],
        transferContext: null
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

    function syncStatusesForPosts(postIds) {
        var ids = Array.isArray(postIds) ? postIds : [postIds];
        ids = ids.map(function (postId) {
            return parseInt(postId, 10);
        }).filter(function (postId, index, arr) {
            return postId > 0 && arr.indexOf(postId) === index;
        });

        if (!cfg.isLoggedIn || !ids.length) {
            return Promise.resolve();
        }

        return Promise.all(ids.map(function (postId) {
            return ensurePostStatus(postId);
        })).then(function () {
            return null;
        });
    }

    function normalizeListName(name) {
        return String(name || '').trim().toLowerCase();
    }

    function findListByName(name) {
        var needle = normalizeListName(name);
        if (!needle || !Array.isArray(state.lists)) {
            return null;
        }

        return state.lists.find(function (list) {
            return normalizeListName(list.name) === needle;
        }) || null;
    }

    function getButtonBehavior(btn) {
        if (!btn) {
            return 'picker';
        }

        return 'direct' === btn.getAttribute('data-behavior') ? 'direct' : 'picker';
    }

    function getButtonTargetListId(btn) {
        if (!btn || 'direct' !== getButtonBehavior(btn)) {
            return '';
        }

        var targetListId = btn.getAttribute('data-target-list-id') || '';
        if (targetListId) {
            return targetListId;
        }

        var targetListName = btn.getAttribute('data-target-list-name') || '';
        var list = findListByName(targetListName);
        return list && list.id ? list.id : '';
    }

    function isButtonActive(btn, status) {
        status = status || { is_favorited: false, list_ids: [] };

        if ('direct' !== getButtonBehavior(btn)) {
            return !!status.is_favorited;
        }

        var targetListId = getButtonTargetListId(btn);
        if (!targetListId || !Array.isArray(status.list_ids)) {
            return false;
        }

        return status.list_ids.indexOf(targetListId) !== -1;
    }

    function setButtonVisualState(btn, active) {
        btn.classList.toggle('is-active', !!active);
        btn.setAttribute('aria-pressed', active ? 'true' : 'false');
        btn.setAttribute('data-is-favorited', active ? '1' : '0');
        btn.setAttribute('data-favorite-hydrated', '1');

        if (!active) {
            btn.classList.remove('is-animating');
        }
    }

    function playActivateAnimation(btn) {
        btn.classList.remove('is-animating');
        void btn.offsetWidth;
        btn.classList.add('is-animating');
        window.setTimeout(function () {
            btn.classList.remove('is-animating');
        }, 380);
    }

    function syncHeartButtons(postId) {
        var status = state.postStatus[String(postId)] || { is_favorited: false };
        document.querySelectorAll('.koopo-favorite-heart[data-post-id="' + postId + '"]').forEach(function (btn) {
            var active = isButtonActive(btn, status);
            var wasActive = btn.getAttribute('data-is-favorited') === '1';
            var hydrated = btn.getAttribute('data-favorite-hydrated') === '1';
            setButtonVisualState(btn, active);

            if (hydrated && !wasActive && active) {
                playActivateAnimation(btn);
            }
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

    function resolveDirectTargetList(btn) {
        return ensureLists().then(function (lists) {
            var targetListId = btn.getAttribute('data-target-list-id') || '';
            var targetListName = (btn.getAttribute('data-target-list-name') || '').trim();

            if (targetListId) {
                var listById = (lists || []).find(function (list) {
                    return list.id === targetListId;
                });
                if (listById) {
                    return listById;
                }
            }

            if (!targetListName) {
                var defaultList = (lists || []).find(function (list) {
                    return !!list.is_default;
                });
                if (defaultList) {
                    btn.setAttribute('data-target-list-id', defaultList.id);
                    return defaultList;
                }

                throw new Error(cfg.i18n.error);
            }

            var existing = findListByName(targetListName);
            if (existing) {
                btn.setAttribute('data-target-list-id', existing.id);
                return existing;
            }

            return api('/lists', {
                method: 'POST',
                body: { name: targetListName }
            }).then(function (createdList) {
                return ensureLists().then(function () {
                    var resolved = createdList && createdList.id ? createdList : findListByName(targetListName);
                    if (!resolved || !resolved.id) {
                        throw new Error(cfg.i18n.error);
                    }

                    btn.setAttribute('data-target-list-id', resolved.id);
                    return resolved;
                });
            });
        });
    }

    function handleDirectFavorite(btn) {
        if (!ensureLoggedIn()) {
            return;
        }

        if (btn.getAttribute('data-busy') === '1') {
            return;
        }

        var postId = parseInt(btn.getAttribute('data-post-id'), 10);
        if (!postId) {
            return;
        }

        var wasActive = btn.getAttribute('data-is-favorited') === '1';

        btn.setAttribute('data-busy', '1');
        btn.setAttribute('aria-busy', 'true');
        btn.classList.add('is-pending');
        setButtonVisualState(btn, !wasActive);

        if (!wasActive) {
            playActivateAnimation(btn);
        }

        resolveDirectTargetList(btn).then(function (list) {
            if (wasActive) {
                return api('/lists/' + encodeURIComponent(list.id) + '/items/' + postId, {
                    method: 'DELETE'
                });
            }

            return api('/lists/' + encodeURIComponent(list.id) + '/items', {
                method: 'POST',
                body: { post_id: postId }
            });
        }).then(function () {
            return Promise.all([ensureLists(), ensurePostStatus(postId)]);
        }).then(function () {
            syncHeartButtons(postId);
            refreshAllDashboards();
        }).catch(function (err) {
            setButtonVisualState(btn, wasActive);
            window.alert(err.message || cfg.i18n.error);
        }).finally(function () {
            btn.removeAttribute('data-busy');
            btn.removeAttribute('aria-busy');
            btn.classList.remove('is-pending');
        });
    }

    function closePicker() {
        var picker = getOrCreatePicker();
        picker.hidden = true;
    }

    function getOrCreateTransferModal() {
        var existing = document.querySelector('.koopo-favorites-transfer');
        if (existing) {
            return existing;
        }

        var modal = document.createElement('div');
        modal.className = 'koopo-favorites-transfer';
        modal.hidden = true;
        modal.innerHTML = '' +
            '<div class="koopo-favorites-transfer__overlay" data-action="close-transfer"></div>' +
            '<div class="koopo-favorites-transfer__panel">' +
                '<button type="button" class="koopo-favorites-transfer__close" data-action="close-transfer" aria-label="' + escapeHtml(cfg.i18n.cancelButton) + '">×</button>' +
                '<form class="koopo-favorites-transfer__form" data-role="transfer-form">' +
                    '<h3 data-role="transfer-title"></h3>' +
                    '<p class="koopo-favorites-transfer__summary" data-role="transfer-summary"></p>' +
                    '<label class="koopo-favorites-transfer__field">' +
                        '<span>' + escapeHtml(cfg.i18n.selectListLabel || 'Add to an existing list') + '</span>' +
                        '<select data-role="transfer-existing-list"></select>' +
                    '</label>' +
                    '<label class="koopo-favorites-transfer__field">' +
                        '<span>' + escapeHtml(cfg.i18n.createNewListLabel || 'Or create a new list') + '</span>' +
                        '<input type="text" data-role="transfer-new-list-name" maxlength="100" placeholder="' + escapeHtml(cfg.i18n.createListPlaceholder) + '" />' +
                    '</label>' +
                    '<p class="koopo-favorites-transfer__hint">' + escapeHtml(cfg.i18n.transferHint || 'Choose an existing list or enter a new name.') + '</p>' +
                    '<div class="koopo-favorites-transfer__actions">' +
                        '<button type="submit" class="button" data-role="transfer-submit"></button>' +
                    '</div>' +
                '</form>' +
            '</div>';
        document.body.appendChild(modal);

        modal.addEventListener('click', function (e) {
            var actionTarget = e.target.closest('[data-action]');
            if (!actionTarget) {
                return;
            }

            if ('close-transfer' === actionTarget.getAttribute('data-action')) {
                closeTransferModal();
            }
        });

        modal.querySelector('[data-role="transfer-form"]').addEventListener('submit', function (e) {
            e.preventDefault();
            submitTransferModal();
        });

        return modal;
    }

    function closeTransferModal() {
        var modal = getOrCreateTransferModal();
        modal.hidden = true;
        state.transferContext = null;
    }

    function openTransferModal(context) {
        if (!ensureLoggedIn()) {
            return;
        }

        ensureLists().then(function () {
            state.transferContext = context || null;
            renderTransferModal();
            getOrCreateTransferModal().hidden = false;
        }).catch(function (err) {
            window.alert(err.message || cfg.i18n.error);
        });
    }

    function renderTransferModal() {
        var modal = getOrCreateTransferModal();
        var context = state.transferContext || {};
        var title = modal.querySelector('[data-role="transfer-title"]');
        var summary = modal.querySelector('[data-role="transfer-summary"]');
        var select = modal.querySelector('[data-role="transfer-existing-list"]');
        var newListInput = modal.querySelector('[data-role="transfer-new-list-name"]');
        var submit = modal.querySelector('[data-role="transfer-submit"]');
        var availableLists = (state.lists || []).filter(function (list) {
            return !context.sourceListId || list.id !== context.sourceListId;
        });

        title.textContent = 'move' === context.operation
            ? (cfg.i18n.moveToListTitle || 'Move to Another List')
            : (cfg.i18n.copyToListTitle || 'Copy to Another List');

        summary.textContent = context.summary || '';
        select.innerHTML = '<option value="">' + escapeHtml(cfg.i18n.selectListPlaceholder || 'Select a list') + '</option>' +
            availableLists.map(function (list) {
                return '<option value="' + escapeHtml(list.id) + '">' + escapeHtml(list.name) + '</option>';
            }).join('');

        newListInput.value = context.defaultListName || '';
        submit.textContent = 'move' === context.operation
            ? (cfg.i18n.moveItemsButton || 'Move Items')
            : (cfg.i18n.copyItemsButton || 'Copy Items');
    }

    function submitTransferModal() {
        var modal = getOrCreateTransferModal();
        var context = state.transferContext || null;

        if (!context || !context.sourceListId || !Array.isArray(context.postIds) || !context.postIds.length) {
            closeTransferModal();
            return;
        }

        var select = modal.querySelector('[data-role="transfer-existing-list"]');
        var newListInput = modal.querySelector('[data-role="transfer-new-list-name"]');
        var submit = modal.querySelector('[data-role="transfer-submit"]');
        var targetListName = newListInput.value.trim();
        var targetListId = select.value;

        if (!targetListName && !targetListId) {
            window.alert(cfg.i18n.transferTargetRequired || cfg.i18n.error);
            return;
        }

        submit.disabled = true;

        api('/items/bulk', {
            method: 'POST',
            body: {
                source_list_id: context.sourceListId,
                post_ids: context.postIds,
                operation: context.operation,
                target_list_id: targetListName ? '' : targetListId,
                target_list_name: targetListName
            }
        }).then(function () {
            closeTransferModal();
            return Promise.all([ensureLists(), syncStatusesForPosts(context.postIds)]);
        }).then(function () {
            refreshAllDashboards();
        }).catch(function (err) {
            window.alert(err.message || cfg.i18n.error);
        }).finally(function () {
            submit.disabled = false;
        });
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

    function formatPostTypeLabel(postType) {
        return String(postType || 'Items')
            .replace(/[_-]+/g, ' ')
            .replace(/\b\w/g, function (match) {
                return match.toUpperCase();
            });
    }

    function getItemPostTypeLabel(item) {
        return item && item.post_type_label ? String(item.post_type_label) : formatPostTypeLabel(item && item.post_type ? item.post_type : 'Items');
    }

    function buildSelectionSummary(count) {
        return String(count || 0) + ' ' + (cfg.i18n.selectedLabel || 'selected');
    }

    function groupItemsByPostType(items) {
        var groups = [];
        var index = {};

        (Array.isArray(items) ? items : []).forEach(function (item) {
            var key = item && item.post_type ? String(item.post_type) : 'items';
            if (!index[key]) {
                index[key] = {
                    key: key,
                    label: getItemPostTypeLabel(item),
                    items: []
                };
                groups.push(index[key]);
            }

            index[key].items.push(item);
        });

        return groups;
    }

    function renderItemThumbnail(item) {
        var url = escapeHtml(item.url || '#');
        var typeLabel = getItemPostTypeLabel(item);

        if (item.thumbnail) {
            return '' +
                '<a class="koopo-favorites-item__thumb" href="' + url + '">' +
                    '<img src="' + escapeHtml(item.thumbnail) + '" alt="' + escapeHtml(item.title || typeLabel) + '" loading="lazy" />' +
                '</a>';
        }

        return '' +
            '<a class="koopo-favorites-item__thumb koopo-favorites-item__thumb--empty" href="' + url + '">' +
                '<span>' + escapeHtml(typeLabel.charAt(0).toUpperCase() || '?') + '</span>' +
            '</a>';
    }

    function renderItemSelection(listId, item, options) {
        if (!options || !options.manage) {
            return '';
        }

        return '' +
            '<label class="koopo-favorites-item__select">' +
                '<input type="checkbox" data-action="select-item" data-list-id="' + escapeHtml(listId) + '" data-post-id="' + Number(item.post_id) + '" />' +
                '<span>' + escapeHtml(cfg.i18n.selectItemLabel || 'Select') + '</span>' +
            '</label>';
    }

    function renderItemActions(listId, item, options) {
        if (!options || !options.manage) {
            return '';
        }

        return '' +
            '<div class="koopo-favorites-item__actions">' +
                '<button type="button" class="button button-small" data-action="copy-item" data-list-id="' + escapeHtml(listId) + '" data-post-id="' + Number(item.post_id) + '">' + escapeHtml(cfg.i18n.copyItemLabel || 'Copy') + '</button>' +
                '<button type="button" class="button button-small" data-action="move-item" data-list-id="' + escapeHtml(listId) + '" data-post-id="' + Number(item.post_id) + '">' + escapeHtml(cfg.i18n.moveItemLabel || 'Move') + '</button>' +
                '<button type="button" class="button button-small" data-action="remove-item" data-list-id="' + escapeHtml(listId) + '" data-post-id="' + Number(item.post_id) + '">' + escapeHtml(cfg.i18n.removeItemLabel || 'Remove') + '</button>' +
            '</div>';
    }

    function renderGroupedItems(items, options) {
        var groups = groupItemsByPostType(items);
        var listId = options && options.listId ? options.listId : '';

        if (!groups.length) {
            return '<p class="koopo-favorites-empty">' + escapeHtml(cfg.i18n.noItems) + '</p>';
        }

        return groups.map(function (group) {
            return '' +
                '<section class="koopo-favorites-group">' +
                    '<header class="koopo-favorites-group__header">' +
                        '<h4>' + escapeHtml(group.label) + '</h4>' +
                        '<span>' + Number(group.items.length) + '</span>' +
                    '</header>' +
                    '<div class="koopo-favorites-group__items">' +
                        group.items.map(function (item) {
                            return '' +
                                '<article class="koopo-favorites-item-card">' +
                                    renderItemThumbnail(item) +
                                    '<div class="koopo-favorites-item__content">' +
                                        '<div class="koopo-favorites-item__top">' +
                                            '<div class="koopo-favorites-item__meta">' + escapeHtml(getItemPostTypeLabel(item)) + '</div>' +
                                            renderItemSelection(listId, item, options) +
                                        '</div>' +
                                        '<a class="koopo-favorites-item__title" href="' + escapeHtml(item.url || '#') + '">' + escapeHtml(item.title || '') + '</a>' +
                                        renderItemActions(listId, item, options) +
                                    '</div>' +
                                '</article>';
                        }).join('') +
                    '</div>' +
                '</section>';
        }).join('');
    }

    function renderBulkToolbar(list) {
        if (!Array.isArray(list.items) || !list.items.length) {
            return '';
        }

        return '' +
            '<div class="koopo-favorites-bulk" data-role="bulk-toolbar">' +
                '<label class="koopo-favorites-bulk__toggle">' +
                    '<input type="checkbox" data-action="select-all" data-list-id="' + escapeHtml(list.id) + '" />' +
                    '<span data-role="select-all-label">' + escapeHtml(cfg.i18n.selectAllLabel || 'Select All') + '</span>' +
                '</label>' +
                '<span class="koopo-favorites-bulk__count" data-role="selection-count">' + escapeHtml(buildSelectionSummary(0)) + '</span>' +
                '<div class="koopo-favorites-bulk__actions">' +
                    '<button type="button" class="button button-small" data-action="bulk-copy" data-list-id="' + escapeHtml(list.id) + '" data-requires-selection="1" disabled>' + escapeHtml(cfg.i18n.bulkCopyLabel || 'Copy Selected') + '</button>' +
                    '<button type="button" class="button button-small" data-action="bulk-move" data-list-id="' + escapeHtml(list.id) + '" data-requires-selection="1" disabled>' + escapeHtml(cfg.i18n.bulkMoveLabel || 'Move Selected') + '</button>' +
                    '<button type="button" class="button button-small" data-action="bulk-remove" data-list-id="' + escapeHtml(list.id) + '" data-requires-selection="1" disabled>' + escapeHtml(cfg.i18n.bulkRemoveLabel || 'Remove Selected') + '</button>' +
                '</div>' +
            '</div>';
    }

    function renderListCard(list) {
        var itemsHtml = renderGroupedItems(list.items, {
            listId: list.id,
            manage: true
        });

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
                    deleteButton +
                '</div>' +
                renderBulkToolbar(list) +
                '<div class="koopo-favorites-items">' + itemsHtml + '</div>' +
                (list.is_public && list.share_url ? '<p class="koopo-favorites-share-url"><a href="' + escapeHtml(list.share_url) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(cfg.i18n.openSharedLabel || 'Open Shared List') + '</a><span>' + escapeHtml(list.share_url) + '</span></p>' : '') +
            '</article>';
    }

    function getSelectedPostIdsFromCard(card) {
        if (!card) {
            return [];
        }

        return Array.prototype.slice.call(card.querySelectorAll('[data-action="select-item"]:checked')).map(function (input) {
            return parseInt(input.getAttribute('data-post-id'), 10);
        }).filter(function (postId, index, arr) {
            return postId > 0 && arr.indexOf(postId) === index;
        });
    }

    function updateListSelectionState(card) {
        if (!card) {
            return;
        }

        var itemCheckboxes = Array.prototype.slice.call(card.querySelectorAll('[data-action="select-item"]'));
        var selectedIds = getSelectedPostIdsFromCard(card);
        var countNode = card.querySelector('[data-role="selection-count"]');
        var selectAllToggle = card.querySelector('[data-action="select-all"]');
        var selectAllLabel = card.querySelector('[data-role="select-all-label"]');

        if (countNode) {
            countNode.textContent = buildSelectionSummary(selectedIds.length);
        }

        if (selectAllToggle) {
            selectAllToggle.checked = itemCheckboxes.length > 0 && selectedIds.length === itemCheckboxes.length;
            selectAllToggle.indeterminate = selectedIds.length > 0 && selectedIds.length < itemCheckboxes.length;
        }

        if (selectAllLabel) {
            selectAllLabel.textContent = selectedIds.length && selectedIds.length === itemCheckboxes.length
                ? (cfg.i18n.clearSelectionLabel || 'Clear Selection')
                : (cfg.i18n.selectAllLabel || 'Select All');
        }

        card.querySelectorAll('[data-requires-selection="1"]').forEach(function (button) {
            button.disabled = !selectedIds.length;
        });
    }

    function updateAllListSelectionStates(scope) {
        (scope || document).querySelectorAll('.koopo-favorites-list').forEach(function (card) {
            updateListSelectionState(card);
        });
    }

    function openItemTransferModal(mode, listId, postIds) {
        var count = Array.isArray(postIds) ? postIds.length : 0;

        if (!count) {
            return;
        }

        openTransferModal({
            operation: mode,
            sourceListId: listId,
            postIds: postIds,
            summary: buildSelectionSummary(count)
        });
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
                updateAllListSelectionStates(listWrap);
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

        app.addEventListener('change', function (e) {
            var input = e.target;
            if (!input) {
                return;
            }

            if (input.matches('[data-action="select-item"]')) {
                updateListSelectionState(input.closest('.koopo-favorites-list'));
                return;
            }

            if (input.matches('[data-action="select-all"]')) {
                var card = input.closest('.koopo-favorites-list');
                if (!card) {
                    return;
                }

                card.querySelectorAll('[data-action="select-item"]').forEach(function (checkbox) {
                    checkbox.checked = input.checked;
                });
                updateListSelectionState(card);
            }
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

            if ('copy-item' === action || 'move-item' === action) {
                var singleMode = 'move-item' === action ? 'move' : 'copy';
                if (!postId) {
                    return;
                }

                openItemTransferModal(singleMode, listId, [Number(postId)]);
                return;
            }

            if ('bulk-copy' === action || 'bulk-move' === action || 'bulk-remove' === action) {
                var card = btn.closest('.koopo-favorites-list');
                var selectedIds = getSelectedPostIdsFromCard(card);

                if (!selectedIds.length) {
                    return;
                }

                if ('bulk-remove' === action) {
                    if (!window.confirm(cfg.i18n.removeItemConfirm)) {
                        return;
                    }

                    api('/items/bulk', {
                        method: 'POST',
                        body: {
                            source_list_id: listId,
                            post_ids: selectedIds,
                            operation: 'remove'
                        }
                    }).then(function () {
                        return Promise.all([ensureLists(), syncStatusesForPosts(selectedIds)]);
                    }).then(function () {
                        refreshAllDashboards();
                    }).catch(function (err) {
                        window.alert(err.message || cfg.i18n.error);
                    });
                    return;
                }

                openItemTransferModal('bulk-move' === action ? 'move' : 'copy', listId, selectedIds);
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
            var owner = '';
            if (list.owner && list.owner.display_name) {
                owner = '' +
                    '<div class="koopo-favorites-shared-owner">' +
                        (list.owner.avatar_url ? '<img class="koopo-favorites-shared-owner__avatar" src="' + escapeHtml(list.owner.avatar_url) + '" alt="' + escapeHtml(list.owner.display_name) + '" loading="lazy" />' : '') +
                        '<div class="koopo-favorites-shared-owner__content">' +
                            '<span>' + escapeHtml(cfg.i18n.sharedByLabel || 'Shared by') + '</span>' +
                            '<strong>' + escapeHtml(list.owner.display_name) + '</strong>' +
                        '</div>' +
                    '</div>';
            }

            target.innerHTML = '' +
                '<article class="koopo-favorites-shared-card">' +
                    '<h3>' + escapeHtml(list.name) + '</h3>' +
                    owner +
                    '<div class="koopo-favorites-shared-actions">' +
                        '<button type="button" class="button" data-action="copy-shared-list" data-slug="' + escapeHtml(slug) + '">' + escapeHtml(cfg.i18n.copySharedLabel || 'Copy As New List') + '</button>' +
                        '<p class="koopo-favorites-shared-actions__status" data-role="shared-copy-status"></p>' +
                    '</div>' +
                    '<div class="koopo-favorites-items">' + renderGroupedItems(list.items, { manage: false }) + '</div>' +
                '</article>';
        }).catch(function () {
            target.innerHTML = '<p class="koopo-favorites-empty">Shared list not found.</p>';
        });

        app.addEventListener('click', function (e) {
            var button = e.target.closest('[data-action="copy-shared-list"]');
            if (!button) {
                return;
            }

            if (!ensureLoggedIn()) {
                return;
            }

            var status = app.querySelector('[data-role="shared-copy-status"]');
            button.disabled = true;

            api('/shared/' + encodeURIComponent(slug) + '/import', {
                method: 'POST'
            }).then(function () {
                if (status) {
                    status.textContent = cfg.i18n.copySharedSuccess || 'Shared list copied to your favorites.';
                }
                return ensureLists();
            }).catch(function (err) {
                if (status) {
                    status.textContent = err.message || cfg.i18n.error;
                }
            }).finally(function () {
                button.disabled = false;
            });
        });
    }

    function initHearts() {
        var hearts = document.querySelectorAll('.koopo-favorite-heart[data-post-id]');
        if (!hearts.length) {
            return;
        }

        var postIds = [];
        var seenPostIds = {};
        var needsLists = false;

        hearts.forEach(function (btn) {
            var postId = parseInt(btn.getAttribute('data-post-id'), 10);
            if ('direct' === getButtonBehavior(btn)) {
                needsLists = true;
            }

            if (cfg.isLoggedIn && postId && !seenPostIds[postId]) {
                seenPostIds[postId] = true;
                postIds.push(postId);
            }
        });

        if (cfg.isLoggedIn && postIds.length) {
            var preload = postIds.map(function (postId) {
                return ensurePostStatus(postId);
            });

            if (needsLists) {
                preload.unshift(ensureLists());
            }

            Promise.all(preload).then(function () {
                postIds.forEach(function (postId) {
                    syncHeartButtons(postId);
                });
            }).catch(function () {});
        }

        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.koopo-favorite-heart[data-post-id]');
            if (!btn) {
                return;
            }

            e.preventDefault();
            if ('direct' === getButtonBehavior(btn)) {
                handleDirectFavorite(btn);
                return;
            }

            openPicker(parseInt(btn.getAttribute('data-post-id'), 10));
        });

        document.addEventListener('keydown', function (e) {
            var btn = e.target.closest('.koopo-favorite-heart[data-post-id]');
            if (!btn) {
                return;
            }

            if ('Enter' !== e.key && ' ' !== e.key) {
                return;
            }

            e.preventDefault();
            if ('direct' === getButtonBehavior(btn)) {
                handleDirectFavorite(btn);
                return;
            }

            openPicker(parseInt(btn.getAttribute('data-post-id'), 10));
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
