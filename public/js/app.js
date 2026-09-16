/* =============================================================================
   IT Asset Manager — front-end glue
   เก็บฟังก์ชันเล็กๆ ที่ใช้ร่วมกันหลายหน้า เช่น confirm-delete, tooltip init
   ============================================================================= */

document.addEventListener('DOMContentLoaded', () => {
    // เปิด Bootstrap tooltip ทั้งหน้า
    const tooltipEls = document.querySelectorAll('[data-bs-toggle="tooltip"]');
    tooltipEls.forEach(el => new bootstrap.Tooltip(el));

    // ปุ่มลบ — ขอ confirm ก่อน submit
    document.querySelectorAll('form[data-confirm]').forEach(form => {
        form.addEventListener('submit', (e) => {
            const msg = form.dataset.confirm || 'ยืนยันการลบ?';
            if (!window.confirm(msg)) e.preventDefault();
        });
    });
});
