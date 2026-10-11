/* Registration settings (admin/competition.php). Nothing is written while the organiser types:
   the save button of the page (fixed at the bottom right) sends the whole form.
   - The level chosen (closed, simple publication, detailed settings) is applied when saving: until
     then the blocks of the level shown are hidden and a note says so.
   - On a closed competition, the payments box shows the tariffs as soon as it is ticked (saved
     with it). */
(function () {
  'use strict';
  var form = document.getElementById('bk-cfg');
  if (!form) return;
  var current = form.getAttribute('data-level');
  var wait = document.getElementById('bk-lvl-wait');

  function levelChanged() {
    var picked = form.querySelector('input[name="level"]:checked');
    var other = !!picked && picked.value !== current;
    Array.prototype.forEach.call(form.querySelectorAll('.bk-lvl'), function (l) {
      var r = l.querySelector('input');
      l.classList.toggle('chosen', !!r && r.checked && other);
    });
    if (wait) wait.hidden = !other;
    Array.prototype.forEach.call(form.querySelectorAll('[data-lvl-block]'), function (b) { b.hidden = other; });
  }

  form.addEventListener('change', function (e) {
    var t = e.target;
    if (t.name === 'level') levelChanged();
    if (t.id === 'bk-payments') {
      var box = document.getElementById('bk-tariffs');
      if (box) box.hidden = !t.checked;
    }
  });
})();
