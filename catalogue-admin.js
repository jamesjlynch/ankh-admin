'use strict';
// Reduce phone photographs before submitting the normal multipart form.
document.querySelectorAll('[data-catalogue-photo]').forEach(input => {
  const form = input.closest('form');
  const status = form.querySelector('[data-photo-status]');
  const preview = form.querySelector('[data-photo-preview]');
  let generation = 0;
  let busy = false;
  form.addEventListener('submit', event => {
    if (busy) { event.preventDefault(); status.textContent = 'Please wait while your photo is prepared.'; }
  });
  input.addEventListener('change', async () => {
    const current = ++generation;
    const file = input.files[0];
    input.setCustomValidity('');
    if (!file) { busy = false; status.textContent = 'No new photograph selected.'; return; }
    busy = true;
    status.textContent = 'Preparing your photograph…';
    const url = URL.createObjectURL(file);
    let keepUrl = false;
    try {
      const image = await new Promise((resolve, reject) => {
        const value = new Image(); value.onload = () => resolve(value); value.onerror = reject; value.src = url;
      });
      if (current !== generation) return;
      const scale = Math.min(1, 1600 / Math.max(image.naturalWidth, image.naturalHeight));
      const canvas = document.createElement('canvas');
      canvas.width = Math.max(1, Math.round(image.naturalWidth * scale));
      canvas.height = Math.max(1, Math.round(image.naturalHeight * scale));
      const context = canvas.getContext('2d');
      context.fillStyle = '#f5f2e9'; context.fillRect(0, 0, canvas.width, canvas.height);
      context.drawImage(image, 0, 0, canvas.width, canvas.height);
      const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', .85));
      if (!blob || blob.size > 8 * 1024 * 1024) throw new Error('Choose a smaller photograph.');
      if (current !== generation) return;
      const transfer = new DataTransfer();
      transfer.items.add(new File([blob], 'catalogue-photo.jpg', {type: 'image/jpeg'}));
      input.files = transfer.files;
      preview.src = canvas.toDataURL('image/jpeg', .65); preview.hidden = false;
      status.textContent = 'Photo ready · ' + Math.round(blob.size / 1024) + ' KB. Save catalogue details to upload it.';
      const remove = form.querySelector('[name="remove_catalogue_photo"]');
      if (remove) remove.checked = false;
    } catch (_) {
      if (current !== generation) return;
      // An already-supported file can still use the server upload when conversion is unavailable.
      if (['image/jpeg', 'image/png', 'image/webp'].includes(file.type) && file.size <= 8 * 1024 * 1024) {
        keepUrl = true; preview.src = url; preview.hidden = false;
        status.textContent = 'Photo selected. Save catalogue details to upload it.';
        // Keep this object URL until the preview has loaded.
        preview.addEventListener('load', () => URL.revokeObjectURL(url), {once: true});
        return;
      }
      input.setCustomValidity('Choose a JPG, PNG or WebP photograph smaller than 8 MB.');
      status.textContent = input.validationMessage;
    } finally {
      if (current === generation) busy = false;
      if (!keepUrl) URL.revokeObjectURL(url);
    }
  });
});
