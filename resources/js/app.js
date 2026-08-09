/**
 * Service worker registration.
 *
 * Same posture as everything below: additive. What registration buys is the
 * install prompt and an offline page instead of the browser's error screen —
 * lose it and the app is still a server-rendered form that works. So the
 * failure path is deliberately silent rather than a message telling an admin
 * standing in a shop aisle about something they can't act on.
 *
 * Deferred to load so it never competes with the page's own requests.
 */
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {});
    });
}

/**
 * Capture screen enhancements.
 *
 * The form is a working multipart POST without any of this — that path is what
 * the feature tests exercise, and what happens if the bundle fails to load on a
 * phone with one bar of signal. Everything here is on top: thumbnails, a real
 * progress bar, and a retry that doesn't make you re-photograph anything.
 *
 * The upload is a foreground XHR on purpose. iOS suspends background uploads
 * when the tab loses focus or the screen locks, so there is no version of this
 * that survives being backgrounded — the honest response is to show progress
 * that keeps moving and to fail loudly enough to retry.
 */

const form = document.querySelector('[data-capture-form]');

if (form) {
    const errors = form.querySelector('[data-capture-errors]');
    const progress = form.querySelector('[data-capture-progress]');
    const bar = form.querySelector('[data-capture-bar]');
    const progressLabel = form.querySelector('[data-capture-progress-label]');
    const submit = form.querySelector('[data-capture-submit]');
    const retry = form.querySelector('[data-capture-retry]');

    const showErrors = (messages) => {
        errors.textContent = '';

        const list = document.createElement('ul');
        messages.forEach((message) => {
            const item = document.createElement('li');
            item.textContent = message;
            list.append(item);
        });

        errors.append(list);
        errors.hidden = false;
    };

    const clearErrors = () => {
        errors.textContent = '';
        errors.hidden = true;
    };

    // Thumbnail + filename per slot, so it's obvious at a glance which photos
    // you've actually taken. Object URLs are revoked on replacement; the page
    // navigates away on success, which cleans up the rest.
    form.querySelectorAll('[data-photo-slot]').forEach((slot) => {
        const input = slot.querySelector('[data-photo-input]');
        const thumb = slot.querySelector('[data-photo-thumb]');
        const status = slot.querySelector('[data-photo-status]');
        const original = status.textContent;
        let objectUrl = null;

        input.addEventListener('change', () => {
            if (objectUrl) {
                URL.revokeObjectURL(objectUrl);
                objectUrl = null;
            }

            const files = Array.from(input.files ?? []);

            if (files.length === 0) {
                thumb.replaceChildren(document.createTextNode('+'));
                status.textContent = original;
                return;
            }

            objectUrl = URL.createObjectURL(files[0]);

            const image = document.createElement('img');
            image.src = objectUrl;
            image.alt = '';
            image.className = 'size-full object-cover';
            thumb.replaceChildren(image);

            status.textContent =
                files.length > 1 ? `${files.length} photos selected` : files[0].name;
        });
    });

    const upload = () => {
        clearErrors();

        submit.disabled = true;
        retry.hidden = true;
        progress.hidden = false;
        bar.style.width = '0%';
        progressLabel.textContent = 'Uploading… keep this screen open.';

        const request = new XMLHttpRequest();
        request.open('POST', form.action);
        request.setRequestHeader('Accept', 'application/json');
        request.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

        request.upload.addEventListener('progress', (event) => {
            if (!event.lengthComputable) {
                return;
            }

            const percent = Math.round((event.loaded / event.total) * 100);
            bar.style.width = `${percent}%`;

            // The bar hitting 100% only means the bytes left the phone. The
            // server is still writing them to R2, and saying "uploaded" here
            // would invite locking the screen during the part that can still
            // fail.
            progressLabel.textContent =
                percent < 100 ? `Uploading… ${percent}%` : 'Storing photos…';
        });

        const fail = (message) => {
            progress.hidden = true;
            submit.disabled = false;
            retry.hidden = false;
            showErrors([message]);
        };

        request.addEventListener('load', () => {
            if (request.status === 201) {
                let redirect = form.action;

                try {
                    redirect = JSON.parse(request.responseText).redirect ?? redirect;
                } catch {
                    // A 201 with an unreadable body still means it stored.
                }

                window.location.assign(redirect);
                return;
            }

            if (request.status === 422) {
                progress.hidden = true;
                submit.disabled = false;

                // Validation failures aren't retryable as-is — something has to
                // change first — so no retry button here.
                try {
                    const body = JSON.parse(request.responseText);
                    showErrors(Object.values(body.errors ?? {}).flat());
                } catch {
                    showErrors(['That submission was rejected. Check the photos and try again.']);
                }

                return;
            }

            if (request.status === 419) {
                fail('Your session expired. Reload the page and sign in again — your photos are still selected.');
                return;
            }

            fail(`Upload failed (${request.status}). Nothing was saved; tap Retry.`);
        });

        request.addEventListener('error', () => {
            fail('Upload failed — the connection dropped. Nothing was saved; tap Retry.');
        });

        request.addEventListener('abort', () => {
            fail('Upload cancelled. Nothing was saved; tap Retry.');
        });

        // The files are still sitting in the inputs, so rebuilding FormData is
        // all a retry needs — no re-photographing.
        request.send(new FormData(form));
    };

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        upload();
    });

    retry.addEventListener('click', upload);
}
