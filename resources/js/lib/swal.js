import Swal from 'sweetalert2';
import { $t } from '../i18n/index.js';

const base = Swal.mixin({
  customClass: {
    popup:         'swal-popup',
    confirmButton: 'swal-btn-confirm',
    cancelButton:  'swal-btn-cancel',
    title:         'swal-title',
    htmlContainer: 'swal-html',
  },
  buttonsStyling: false,
  reverseButtons: true,
});

export function useSwal() {
  function confirm({ title = $t('ยืนยันการดำเนินการ?'), text = '', icon = 'question', confirmText = $t('ยืนยัน'), cancelText = $t('ยกเลิก'), ...options } = {}) {
    return base.fire({
      title,
      text,
      icon: options.imageUrl ? null : icon,
      showCancelButton: true,
      confirmButtonText: confirmText,
      cancelButtonText: cancelText,
      ...options,
    });
  }

  function alert({ title = '', text = '', icon = 'info', confirmText = $t('ตกลง') } = {}) {
    return base.fire({ title, text, icon, confirmButtonText: confirmText });
  }

  function success(title = $t('สำเร็จ!'), text = '') {
    return base.fire({ title, text, icon: 'success', confirmButtonText: $t('ตกลง') });
  }

  function error(title = $t('เกิดข้อผิดพลาด'), text = '') {
    return base.fire({ title, text, icon: 'error', confirmButtonText: $t('ตกลง') });
  }

  function warning(title = $t('คำเตือน'), text = '') {
    return base.fire({ title, text, icon: 'warning', confirmButtonText: $t('ตกลง') });
  }

  return { confirm, alert, success, error, warning };
}

export default useSwal;
