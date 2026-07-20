import axios from 'axios';
import api from './axios';

/**
 * Uploads a file to R2, preferring a presigned PUT straight from the browser.
 *
 * Going through the API means the file travels twice (client → server → R2) and
 * has to fit under post_max_size and whatever body limit the proxy enforces —
 * painful for a 200MB video. The presigned path sends it once, to R2 directly.
 *
 * Falls back to the plain POST whenever presigning isn't available (local dev
 * runs on the 'public' disk) or the PUT fails, so callers get a URL either way.
 *
 * @param {File} file
 * @param {(loaded: number, total: number) => void} [onProgress]
 * @returns {Promise<string>} the file's public URL
 */
export async function uploadMedia(file, onProgress) {
  const report = (loaded, total) => onProgress?.(loaded, total || file.size);

  try {
    const { data } = await api.post('/admin/media/presign', {
      filename: file.name,
      content_type: file.type,
      size: file.size,
    });

    if (data.data?.supported) {
      const { upload_url: uploadUrl, headers, path } = data.data;

      // Bare axios on purpose: the api instance would attach our Authorization
      // header, which R2 rejects as a conflicting credential on a signed URL.
      await axios.put(uploadUrl, file, {
        headers,
        onUploadProgress: (e) => report(e.loaded, e.total),
      });

      const confirmed = await api.post('/admin/media/confirm', { path });
      return confirmed.data.data.url;
    }
  } catch (e) {
    // A rejected size/type is the server's final answer — don't retry it
    // through the slower path just to be refused again.
    if (e.response?.status === 422) throw e;
  }

  const formData = new FormData();
  formData.append('file', file);
  const res = await api.post('/admin/upload-image', formData, {
    onUploadProgress: (e) => report(e.loaded, e.total),
  });

  return res.data.data.url;
}
