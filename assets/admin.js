// Every listener is delegated to the document: a listener bound to the elements present when
// this module is evaluated would never reach the buttons of a page replaced since, and they
// would answer nothing, with no error anywhere.

// --- Cover cropper -------------------------------------------------------------------------
// A cover that does not already have the stored size is framed here, then replaced in its
// file input by the framed rendering just before the form leaves. The server checks the size
// again, so a browser where this module does not run gets an explicit refusal instead.
const states = new WeakMap();
let drag = null;

function cropperFor(input) {
    return document.querySelector('[data-cover-cropper="' + CSS.escape(input.id) + '"]');
}

function geometry(cropper, state) {
    const width = Number(cropper.dataset.coverWidth);
    const height = Number(cropper.dataset.coverHeight);
    const scale = Math.max(width / state.bitmap.width, height / state.bitmap.height) * state.zoom;
    const sourceWidth = width / scale;
    const sourceHeight = height / scale;

    state.centerX = Math.min(Math.max(state.centerX, sourceWidth / 2), state.bitmap.width - sourceWidth / 2);
    state.centerY = Math.min(Math.max(state.centerY, sourceHeight / 2), state.bitmap.height - sourceHeight / 2);

    return { width, height, scale, sourceWidth, sourceHeight };
}

function draw(cropper, state) {
    const canvas = cropper.querySelector('[data-cover-cropper-canvas]');
    const g = geometry(cropper, state);
    const context = canvas.getContext('2d');

    context.imageSmoothingQuality = 'high';
    context.clearRect(0, 0, g.width, g.height);
    context.drawImage(
        state.bitmap,
        state.centerX - g.sourceWidth / 2,
        state.centerY - g.sourceHeight / 2,
        g.sourceWidth,
        g.sourceHeight,
        0,
        0,
        g.width,
        g.height,
    );
}

document.addEventListener('change', async function (event) {
    const input = event.target;
    if (!(input instanceof HTMLInputElement) || 'file' !== input.type) return;

    const cropper = cropperFor(input);
    if (!cropper) return;

    states.delete(cropper);
    cropper.classList.add('hidden');

    const file = input.files[0];
    if (!file) return;

    let bitmap;
    try {
        bitmap = await createImageBitmap(file);
    } catch {
        // An unreadable file goes up as chosen: the server names what is wrong with it.
        return;
    }

    const width = Number(cropper.dataset.coverWidth);
    const height = Number(cropper.dataset.coverHeight);
    if (bitmap.width === width && bitmap.height === height) return;

    const state = { input: input, bitmap: bitmap, zoom: 1, centerX: bitmap.width / 2, centerY: bitmap.height / 2 };
    states.set(cropper, state);

    cropper.querySelector('[data-cover-cropper-zoom]').value = '1';
    cropper.querySelector('[data-cover-cropper-warning]').classList.toggle('hidden', bitmap.width >= width && bitmap.height >= height);
    cropper.classList.remove('hidden');
    draw(cropper, state);
});

document.addEventListener('input', function (event) {
    const range = event.target.closest('[data-cover-cropper-zoom]');
    if (!range) return;

    const cropper = range.closest('[data-cover-cropper]');
    const state = states.get(cropper);
    if (!state) return;

    state.zoom = Number(range.value);
    draw(cropper, state);
});

document.addEventListener('pointerdown', function (event) {
    const canvas = event.target.closest('[data-cover-cropper-canvas]');
    if (!canvas) return;

    const cropper = canvas.closest('[data-cover-cropper]');
    const state = states.get(cropper);
    if (!state) return;

    canvas.setPointerCapture(event.pointerId);
    drag = { cropper: cropper, state: state, canvas: canvas, x: event.clientX, y: event.clientY };
});

document.addEventListener('pointermove', function (event) {
    if (!drag) return;

    const g = geometry(drag.cropper, drag.state);
    const sourcePerCssPixel = g.width / drag.canvas.clientWidth / g.scale;

    drag.state.centerX -= (event.clientX - drag.x) * sourcePerCssPixel;
    drag.state.centerY -= (event.clientY - drag.y) * sourcePerCssPixel;
    drag.x = event.clientX;
    drag.y = event.clientY;
    draw(drag.cropper, drag.state);
});

function endDrag() {
    drag = null;
}

document.addEventListener('pointerup', endDrag);
document.addEventListener('pointercancel', endDrag);

function exportCrop(cropper, state) {
    const canvas = cropper.querySelector('[data-cover-cropper-canvas]');

    return new Promise(function (resolve, reject) {
        // PNG keeps the framing lossless: the server encodes the stored WebP once, from it.
        canvas.toBlob(function (blob) {
            if (!blob) {
                reject(new Error(cropper.querySelector('[data-cover-cropper-warning]').dataset.coverCropperFailure));

                return;
            }

            const name = state.input.files[0].name.replace(/\.[^.]+$/, '') + '.png';
            const transfer = new DataTransfer();
            transfer.items.add(new File([blob], name, { type: 'image/png' }));
            state.input.files = transfer.files;
            states.delete(cropper);
            resolve();
        }, 'image/png');
    });
}

document.addEventListener('submit', function (event) {
    const form = event.target;
    const pending = Array.from(form.querySelectorAll('[data-cover-cropper]')).filter(function (cropper) {
        return states.has(cropper);
    });
    if (0 === pending.length) return;

    event.preventDefault();
    const submitter = event.submitter;

    Promise.all(pending.map(function (cropper) {
        return exportCrop(cropper, states.get(cropper));
    })).then(function () {
        form.requestSubmit(submitter);
    }, function (error) {
        pending.forEach(function (cropper) {
            const warning = cropper.querySelector('[data-cover-cropper-warning]');
            warning.textContent = error.message;
            warning.classList.remove('hidden');
        });
    });
});

// --- Markdown editor -----------------------------------------------------------------------
// An attached file is inserted where the cursor stands, as a `blog-file:` reference the
// public page turns into the file's address.
document.addEventListener('click', function (event) {
    const button = event.target.closest('[data-mulertech-blog-insert]');
    if (!button) return;

    const editor = document.querySelector('[data-mulertech-blog-editor]');
    if (!editor) return;

    const text = button.dataset.mulertechBlogInsert;
    editor.focus();
    editor.setRangeText(text, editor.selectionStart, editor.selectionEnd, 'end');
    editor.dispatchEvent(new Event('input', { bubbles: true }));
});

// --- Confirmation --------------------------------------------------------------------------
document.addEventListener('submit', function (event) {
    const message = event.target.dataset.mulertechBlogConfirm;
    if (message && !window.confirm(message)) {
        event.preventDefault();
    }
});
