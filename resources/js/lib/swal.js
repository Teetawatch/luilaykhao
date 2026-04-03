import Swal from 'sweetalert2';

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
  function confirm({ title = 'ยืนยันการดำเนินการ?', text = '', icon = 'question', confirmText = 'ยืนยัน', cancelText = 'ยกเลิก' } = {}) {
    return base.fire({
      title,
      text,
      icon,
      showCancelButton: true,
      confirmButtonText: confirmText,
      cancelButtonText: cancelText,
    });
  }

  function alert({ title = '', text = '', icon = 'info', confirmText = 'ตกลง' } = {}) {
    return base.fire({ title, text, icon, confirmButtonText: confirmText });
  }

  function success(title = 'สำเร็จ!', text = '') {
    return base.fire({ title, text, icon: 'success', confirmButtonText: 'ตกลง' });
  }

  function error(title = 'เกิดข้อผิดพลาด', text = '') {
    return base.fire({ title, text, icon: 'error', confirmButtonText: 'ตกลง' });
  }

  function warning(title = 'คำเตือน', text = '') {
    return base.fire({ title, text, icon: 'warning', confirmButtonText: 'ตกลง' });
  }

  return { confirm, alert, success, error, warning };
}

export default useSwal;
