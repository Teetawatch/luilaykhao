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
        headers: browserSafeHeaders(headers),
        onUploadProgress: (e) => report(e.loaded, e.total),
      });

      const confirmed = await api.post('/admin/media/confirm', { path });
      return confirmed.data.data.url;
    }
  } catch (e) {
    // A rejected size/type is the server's final answer — don't retry it
    // through the slower path just to be refused again.
    if (e.response?.status === 422) throw e;

    // Anything else and we're about to fall back. Say why here: the fallback's
    // own failure is what surfaces otherwise, and it hides the real cause.
    console.error('[mediaUpload] direct-to-R2 upload failed, falling back', e);

    // Past the proxy/PHP body limit the fallback cannot succeed — it would just
    // push the file for minutes and fail. Report the real problem instead.
    if (file.size > DIRECT_UPLOAD_ONLY_BYTES) {
      throw new Error(
        `อัปโหลดตรงไป R2 ไม่สำเร็จ (${e.message}) — ไฟล์ใหญ่เกินกว่าจะอัปผ่านเซิร์ฟเวอร์แทนได้ ` +
        'กรุณาตรวจสอบ CORS policy ของ R2 bucket'
      );
    }
  }

  const formData = new FormData();
  formData.append('file', file);
  const res = await api.post('/admin/upload-image', formData, {
    onUploadProgress: (e) => report(e.loaded, e.total),
  });

  return res.data.data.url;
}

/** Files past this size can only realistically go direct to R2. */
const DIRECT_UPLOAD_ONLY_BYTES = 90 * 1024 * 1024;

/**
 * The signer hands back Guzzle-shaped headers — values are arrays, and the set
 * includes Host, which browsers forbid scripts from setting. Flatten the values
 * and drop the ones the browser fills in itself.
 */
function browserSafeHeaders(headers) {
  const forbidden = ['host', 'content-length', 'connection'];

  return Object.entries(headers || {}).reduce((out, [key, value]) => {
    if (!forbidden.includes(key.toLowerCase())) {
      out[key] = Array.isArray(value) ? value.join(', ') : value;
    }
    return out;
  }, {});
}
