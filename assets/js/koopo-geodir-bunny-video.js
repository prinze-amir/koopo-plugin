(function () {
    'use strict';

    function parseConfig(root) {
        try {
            return JSON.parse(root.getAttribute('data-config') || '{}');
        } catch (error) {
            return {};
        }
    }

    function parsePayload(input) {
        try {
            var value = JSON.parse(input.value || '[]');
            return Array.isArray(value) ? value : [];
        } catch (error) {
            return [];
        }
    }

    function escapeHtml(value) {
        return String(value || '').replace(/[&<>"']/g, function (char) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            }[char];
        });
    }

    function request(config, path, body) {
        return fetch(String(config.root || '') + path, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': String(config.nonce || '')
            },
            body: JSON.stringify(body || {})
        }).then(function (response) {
            return response.json().then(function (payload) {
                if (!response.ok) {
                    throw new Error(payload && payload.message ? payload.message : 'Request failed.');
                }
                return payload;
            });
        });
    }

    function updatePayload(payloadInput, videos) {
        payloadInput.value = JSON.stringify(videos);
        payloadInput.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function renderList(root, config, videos) {
        var list = root.querySelector('[data-koopo-gd-bunny-list]');
        var payloadInput = root.querySelector('[data-koopo-gd-bunny-payload]');
        if (!list || !payloadInput) {
            return;
        }

        updatePayload(payloadInput, videos);

        if (!videos.length) {
            list.innerHTML = '';
            return;
        }

        list.innerHTML = videos.map(function (video) {
            var id = String(video.provider_video_id || '');
            var title = String(video.title || 'Uploaded video');
            var status = String(video.status || 'processing');
            return '' +
                '<article class="koopo-gd-bunny-field__item" data-provider-video-id="' + escapeHtml(id) + '">' +
                '  <div class="koopo-gd-bunny-field__item-copy">' +
                '    <strong>' + escapeHtml(title) + '</strong>' +
                '    <span>' + escapeHtml(status) + '</span>' +
                '  </div>' +
                '  <button type="button" class="button-link-delete" data-koopo-gd-bunny-remove="' + escapeHtml(id) + '">' + escapeHtml((config.text && config.text.remove) || 'Remove') + '</button>' +
                '</article>';
        }).join('');
    }

    function setStatus(root, message) {
        var status = root.querySelector('[data-koopo-gd-bunny-status]');
        if (status) {
            status.textContent = message || '';
        }
    }

    function setProgress(root, value) {
        var wrap = root.querySelector('[data-koopo-gd-bunny-progress]');
        var bar = root.querySelector('[data-koopo-gd-bunny-progress-bar]');
        if (!wrap || !bar) {
            return;
        }

        if (value === null || value === undefined) {
            wrap.hidden = true;
            bar.style.width = '0%';
            return;
        }

        wrap.hidden = false;
        bar.style.width = String(Math.max(0, Math.min(100, value))) + '%';
    }

    function initUploader(root) {
        var config = parseConfig(root);
        var input = root.querySelector('[data-koopo-gd-bunny-file]');
        var pick = root.querySelector('[data-koopo-gd-bunny-pick]');
        var payloadInput = root.querySelector('[data-koopo-gd-bunny-payload]');
        var videos = payloadInput ? parsePayload(payloadInput) : [];

        renderList(root, config, videos);

        if (!input || !pick || !payloadInput) {
            return;
        }

        pick.addEventListener('click', function () {
            input.click();
        });

        input.addEventListener('change', function () {
            var file = input.files && input.files[0] ? input.files[0] : null;
            if (!file) {
                return;
            }

            if (!window.tus || !window.tus.Upload) {
                setStatus(root, 'Upload library is unavailable.');
                return;
            }

            pick.disabled = true;
            setStatus(root, (config.text && config.text.uploading) || 'Uploading...');
            setProgress(root, 0);

            request(config, '/init', {
                post_id: Number(config.postId || 0),
                name: file.name,
                mime: file.type || '',
                size: file.size || 0
            }).then(function (init) {
                if (!init || !init.upload || !init.upload.url || !init.upload.headers) {
                    throw new Error('Upload could not be initialized.');
                }

                return new Promise(function (resolve, reject) {
                    var upload = new window.tus.Upload(file, {
                        endpoint: init.upload.url,
                        headers: init.upload.headers,
                        retryDelays: [0, 1000, 3000, 5000],
                        chunkSize: 5 * 1024 * 1024,
                        metadata: {
                            filename: file.name,
                            filetype: file.type || 'video/mp4'
                        },
                        onError: reject,
                        onProgress: function (uploaded, total) {
                            if (total > 0) {
                                setProgress(root, Math.round((uploaded / total) * 100));
                            }
                        },
                        onSuccess: function () {
                            resolve(init);
                        }
                    });
                    upload.start();
                });
            }).then(function (init) {
                setStatus(root, (config.text && config.text.processing) || 'Processing');
                return request(config, '/complete', {
                    post_id: Number(config.postId || 0),
                    provider_video_id: init.provider_video_id,
                    title: file.name
                });
            }).then(function (item) {
                videos = videos.filter(function (video) {
                    return String(video.provider_video_id || '') !== String(item.provider_video_id || '');
                });
                videos.push(item);
                renderList(root, config, videos);
                setStatus(root, (config.text && config.text.processing) || 'Processing');
            }).catch(function (error) {
                setStatus(root, error && error.message ? error.message : ((config.text && config.text.failed) || 'Upload failed.'));
            }).then(function () {
                pick.disabled = false;
                input.value = '';
                setProgress(root, null);
            });
        });

        root.addEventListener('click', function (event) {
            var remove = event.target.closest('[data-koopo-gd-bunny-remove]');
            if (!remove) {
                return;
            }

            event.preventDefault();
            var providerVideoId = String(remove.getAttribute('data-koopo-gd-bunny-remove') || '');
            videos = videos.filter(function (video) {
                return String(video.provider_video_id || '') !== providerVideoId;
            });
            renderList(root, config, videos);

            if (Number(config.postId || 0) > 0 && providerVideoId) {
                request(config, '/remove', {
                    post_id: Number(config.postId || 0),
                    provider_video_id: providerVideoId
                }).catch(function () {});
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-koopo-gd-bunny-video]').forEach(initUploader);
    });
}());
