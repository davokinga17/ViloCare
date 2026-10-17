/*
 * Reusable "Take photo" button.
 *
 *   <button type="button" data-camera-capture data-target="#photo" data-title="Take profile photo">Take Photo</button>
 *
 * data-target   CSS selector of the <input type="file"> that should receive the photo (required)
 * data-title    modal heading (optional)
 * data-max-size longest side of the saved JPEG in pixels (default 1024)
 * data-quality  JPEG quality 0..1 (default 0.85)
 * data-mirror   "false" to save the picture un-mirrored (default: mirrored, like the live preview)
 *
 * Desktop / laptop: a modal shows the live camera with Capture, Retake, Use photo and Cancel. The JPEG is put into the
 * target file input and a normal "change" event is fired, so the page's existing preview and upload code is unchanged.
 * "Choose a file" is always available, and is what the user is offered when there is no usable camera.
 * Phones and tablets (touch devices): the native camera input (capture="user") is used instead, as before.
 * The camera is released whenever the modal closes and when the page is left.
 */
(function () {
    'use strict';

    var STYLE_ID = 'camera-capture-style';
    var active = null; // the one open session: { stop() }

    function isNativeCameraDevice() {
        // Phones/tablets: the primary pointer is a finger. Touch-screen laptops have a mouse/touchpad as the primary
        // pointer, and the desktop app (Electron) is never treated as a phone.
        var coarse = window.matchMedia && window.matchMedia('(pointer: coarse)').matches;
        return !!coarse && !/Electron\//.test(navigator.userAgent);
    }

    function describeError(err) {
        var name = err && err.name;
        var windows = /Windows/.test(navigator.userAgent);
        if (name === 'NotFoundError' || name === 'DevicesNotFoundError' || name === 'OverconstrainedError') {
            return 'No camera found. Plug in a camera, or choose a file instead.';
        }
        if (name === 'NotAllowedError' || name === 'PermissionDeniedError' || name === 'SecurityError') {
            return windows
                ? 'Camera is blocked: check Windows Settings > Privacy > Camera (turn on "Let desktop apps access your camera"), or choose a file instead.'
                : 'Camera is blocked: allow camera access for this app in your system settings, or choose a file instead.';
        }
        if (name === 'NotReadableError' || name === 'AbortError' || name === 'TrackStartError') {
            return 'The camera is being used by another program. Close that program and try again, or choose a file instead.';
        }
        return 'The camera could not be started. Try again, or choose a file instead.';
    }

    function injectStyle() {
        if (document.getElementById(STYLE_ID)) {
            return;
        }
        var style = document.createElement('style');
        style.id = STYLE_ID;
        style.textContent =
            '.cc-overlay{position:fixed;inset:0;background:rgba(15,23,42,.75);z-index:9999;display:flex;align-items:center;justify-content:center;padding:16px}' +
            '.cc-dialog{background:#fff;border-radius:var(--radius-lg,12px);max-width:460px;width:100%;box-shadow:var(--shadow-lg,0 20px 40px rgba(0,0,0,.3));overflow:hidden;display:flex;flex-direction:column}' +
            '.cc-head{padding:14px 18px;border-bottom:1px solid var(--border,#e2e8f0);display:flex;align-items:center;justify-content:space-between;background:#f8fafc}' +
            '.cc-title{font-size:1rem;font-weight:700;color:#0f172a;margin:0}' +
            '.cc-x{background:none;border:0;font-size:1.25rem;color:#64748b;cursor:pointer;line-height:1;padding:4px 8px}' +
            '.cc-stage{padding:16px;background:#0f172a;display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:280px;position:relative}' +
            '.cc-stage video,.cc-stage canvas{width:100%;max-height:280px;object-fit:cover;border-radius:8px;background:#000}' +
            '.cc-stage video.cc-mirror{transform:scaleX(-1)}' +
            '.cc-msg{color:#e2e8f0;text-align:center;padding:12px 8px;font-size:.9rem;line-height:1.5}' +
            '.cc-msg.cc-error{color:#fecaca}' +
            '.cc-foot{padding:14px 18px;border-top:1px solid var(--border,#e2e8f0);display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap}' +
            '.cc-foot .cc-group{display:flex;gap:10px;align-items:center;flex-wrap:wrap}' +
            '.cc-link{background:none;border:0;color:#0d9488;font-weight:600;cursor:pointer;padding:6px 2px;font-size:.85rem;text-decoration:underline}' +
            '.cc-hidden{display:none!important}';
        document.head.appendChild(style);
    }

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text) {
            node.textContent = text;
        }
        return node;
    }

    function setFiles(input, file) {
        var transfer = new DataTransfer();
        transfer.items.add(file);
        input.files = transfer.files;
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }

    /** Draw the current video frame, reduced so its longest side is at most maxSize, and return a JPEG blob. */
    function snapshot(video, maxSize, quality, mirror) {
        return new Promise(function (resolve) {
            var w = video.videoWidth || 640;
            var h = video.videoHeight || 480;
            var scale = Math.min(1, maxSize / Math.max(w, h));
            var canvas = document.createElement('canvas');
            canvas.width = Math.max(1, Math.round(w * scale));
            canvas.height = Math.max(1, Math.round(h * scale));
            var ctx = canvas.getContext('2d');
            if (mirror) {
                ctx.translate(canvas.width, 0);
                ctx.scale(-1, 1);
            }
            ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
            canvas.toBlob(function (blob) {
                resolve({ blob: blob, canvas: canvas });
            }, 'image/jpeg', quality);
        });
    }

    /** Phones: hand over to the native camera app through a capture input. */
    function useNativeCamera(target) {
        var input = document.createElement('input');
        input.type = 'file';
        input.accept = 'image/*';
        input.setAttribute('capture', 'user');
        input.addEventListener('change', function () {
            if (input.files && input.files[0]) {
                setFiles(target, input.files[0]);
            }
        });
        input.click();
    }

    function openModal(button, target) {
        if (active) {
            return;
        }
        injectStyle();

        var maxSize = parseInt(button.dataset.maxSize, 10) || 1024;
        var quality = parseFloat(button.dataset.quality) || 0.85;
        var mirror = button.dataset.mirror !== 'false';
        var previouslyFocused = document.activeElement;

        var overlay = el('div', 'cc-overlay');
        var dialog = el('div', 'cc-dialog');
        dialog.setAttribute('role', 'dialog');
        dialog.setAttribute('aria-modal', 'true');
        dialog.setAttribute('aria-labelledby', 'cc-title');

        var head = el('div', 'cc-head');
        var title = el('h3', 'cc-title', button.dataset.title || 'Take photo');
        title.id = 'cc-title';
        var closeX = el('button', 'cc-x', '✕');
        closeX.type = 'button';
        closeX.setAttribute('aria-label', 'Close');
        head.append(title, closeX);

        var stage = el('div', 'cc-stage');
        var video = el('video', mirror ? 'cc-mirror' : '');
        video.autoplay = true;
        video.muted = true;
        video.setAttribute('playsinline', '');
        var still = el('canvas', 'cc-hidden');
        var message = el('div', 'cc-msg', 'Starting the camera…');
        message.setAttribute('role', 'status');
        message.setAttribute('aria-live', 'polite');
        video.classList.add('cc-hidden');
        stage.append(video, still, message);

        var foot = el('div', 'cc-foot');
        var chooseFile = el('button', 'cc-link', 'Choose a file');
        chooseFile.type = 'button';
        var group = el('div', 'cc-group');
        var cancel = btn('Cancel', 'btn btn-secondary');
        var capture = btn('Capture', 'btn btn-primary cc-hidden');
        var retake = btn('Retake', 'btn btn-secondary cc-hidden');
        var use = btn('Use photo', 'btn btn-primary cc-hidden');
        group.append(cancel, retake, capture, use);
        foot.append(chooseFile, group);

        dialog.append(head, stage, foot);
        overlay.appendChild(dialog);
        document.body.appendChild(overlay);

        function btn(label, cls) {
            var b = el('button', cls, label);
            b.type = 'button';
            return b;
        }

        var stream = null;
        var closed = false;
        var captured = null;

        function show(node, on) {
            node.classList.toggle('cc-hidden', !on);
        }

        function stopStream() {
            if (stream) {
                stream.getTracks().forEach(function (track) { track.stop(); });
                stream = null;
            }
            video.srcObject = null;
        }

        function close() {
            if (closed) {
                return;
            }
            closed = true;
            stopStream();
            document.removeEventListener('keydown', onKey, true);
            overlay.remove();
            active = null;
            if (previouslyFocused && previouslyFocused.focus) {
                previouslyFocused.focus();
            }
        }

        function problem(text) {
            stopStream();
            show(video, false);
            show(still, false);
            show(capture, false);
            show(retake, false);
            show(use, false);
            message.className = 'cc-msg cc-error';
            message.textContent = text;
            show(message, true);
            chooseFile.focus();
        }

        function onKey(e) {
            if (e.key === 'Escape') {
                e.preventDefault();
                close();
            } else if (e.key === 'Tab') {
                var items = Array.prototype.filter.call(dialog.querySelectorAll('button'), function (b) { return b.offsetParent !== null; });
                if (!items.length) {
                    return;
                }
                var first = items[0];
                var last = items[items.length - 1];
                if (e.shiftKey && document.activeElement === first) {
                    e.preventDefault();
                    last.focus();
                } else if (!e.shiftKey && document.activeElement === last) {
                    e.preventDefault();
                    first.focus();
                }
            }
        }
        document.addEventListener('keydown', onKey, true);

        closeX.addEventListener('click', close);
        cancel.addEventListener('click', close);
        overlay.addEventListener('mousedown', function (e) { if (e.target === overlay) { close(); } });

        chooseFile.addEventListener('click', function () {
            close();
            target.click();
        });

        capture.addEventListener('click', function () {
            snapshot(video, maxSize, quality, mirror).then(function (shot) {
                if (closed || !shot.blob) {
                    return;
                }
                captured = shot.blob;
                still.width = shot.canvas.width;
                still.height = shot.canvas.height;
                still.getContext('2d').drawImage(shot.canvas, 0, 0);
                show(video, false);
                show(still, true);
                show(capture, false);
                show(retake, true);
                show(use, true);
                use.focus();
            });
        });

        retake.addEventListener('click', function () {
            captured = null;
            show(still, false);
            show(video, true);
            show(retake, false);
            show(use, false);
            show(capture, true);
            capture.focus();
        });

        use.addEventListener('click', function () {
            if (!captured) {
                return;
            }
            var file = new File([captured], 'camera-' + Date.now() + '.jpg', { type: 'image/jpeg' });
            try {
                setFiles(target, file);
            } catch (e) {
                problem('This browser cannot attach the picture. Choose a file instead.');
                return;
            }
            close();
        });

        active = { stop: close };
        cancel.focus();

        if (!navigator.mediaDevices || typeof navigator.mediaDevices.getUserMedia !== 'function') {
            problem('The camera is not available in this window. Choose a file instead.');
            return;
        }

        navigator.mediaDevices.getUserMedia({
            video: { facingMode: 'user', width: { ideal: 1280 }, height: { ideal: 960 } },
            audio: false
        }).then(function (s) {
            if (closed) {
                // Closed while the browser was still asking: release the camera straight away.
                s.getTracks().forEach(function (track) { track.stop(); });
                return;
            }
            stream = s;
            // If the camera is unplugged or taken by another program while open, say so instead of freezing.
            s.getVideoTracks().forEach(function (track) {
                track.addEventListener('ended', function () {
                    if (!closed && !captured) {
                        problem('The camera was disconnected. Plug it back in and try again, or choose a file instead.');
                    }
                });
            });
            video.srcObject = s;
            return video.play().catch(function () { /* autoplay handled by the autoplay attribute */ }).then(function () {
                if (closed) {
                    return;
                }
                show(message, false);
                show(video, true);
                show(capture, true);
                capture.focus();
            });
        }).catch(function (err) {
            if (!closed) {
                problem(describeError(err));
            }
        });
    }

    document.addEventListener('click', function (e) {
        var button = e.target.closest && e.target.closest('[data-camera-capture]');
        if (!button) {
            return;
        }
        var target = document.querySelector(button.dataset.target || '');
        if (!target) {
            return;
        }
        e.preventDefault();
        if (isNativeCameraDevice()) {
            useNativeCamera(target);
        } else {
            openModal(button, target);
        }
    });

    // Always let go of the camera when the page is left (navigation, reload, window closed).
    ['pagehide', 'beforeunload'].forEach(function (name) {
        window.addEventListener(name, function () {
            if (active) {
                active.stop();
            }
        });
    });
})();
