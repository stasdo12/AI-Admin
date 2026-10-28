(function () {
  var URL_COLUMNS = '{{ columns_url|raw }}'.replace(/&amp;/g, '&');
  var ACC = {{ access_json|raw }}, PRESETS = {{ presets_json|raw }}, STATS = {{ stats_json|raw }};
  var LV = ['none', 'read', 'write', 'full'];
  var CUSTOM = {{ acl_custom_json|raw }}; // the owner's own preset ("My settings"), null until they make one
  var T = {
    none: '{{ text_level_none|e('js') }}', read: '{{ text_level_read|e('js') }}', write: '{{ text_level_write|e('js') }}', full: '{{ text_level_full|e('js') }}', mixed_short: '{{ ui_level_mixed|e('js') }}',
    fields: '{{ text_fields|e('js') }}', tables: '{{ ui_db_tables|e('js') }}', tables_short: '{{ text_tables|e('js') }}', mixed: '{{ text_mixed|e('js') }}', custom: '{{ ui_level_custom|e('js') }}',
    read_col: '{{ text_read_col|e('js') }}', edit_col: '{{ text_edit_col|e('js') }}', pii: '{{ text_badge_personal|e('js') }}', money: '{{ text_badge_money|e('js') }}', tech: '{{ text_badge_tech|e('js') }}', sec: '{{ text_badge_secret|e('js') }}',
    no_matches: '{{ text_no_matches|e('js') }}', hidden_n: '{{ text_hidden_n|e('js') }}', edit_n: '{{ text_edit_n|e('js') }}', rows: '{{ column_rows|e('js') }}', masked: '{{ text_badge_masked|e('js') }}', no_edit: '{{ text_badge_no_edit|e('js') }}', fields_of: '{{ text_fields_of|e('js') }}',
    tb_table: '{{ ui_tb_table|e('js') }}', tb_rows: '{{ ui_tb_rows|e('js') }}', tb_level: '{{ ui_tb_level|e('js') }}', subs_n: '{{ ui_subs_n|e('js') }}',
    found_in_fields: '{{ ui_found_in_fields|e('js') }}', all_none: '{{ ui_all_none|e('js') }}',
    del_col: '{{ ui_del_col|e('js') }}', all_kinds: '{{ ui_all_kinds|e('js') }}',
    profile: { read: '{{ ui_access_lv_read|e('js') }}', write: '{{ ui_access_lv_write|e('js') }}', all: '{{ ui_access_lv_all|e('js') }}', custom: '{{ ui_access_lv_custom|e('js') }}', none: '{{ ui_access_lv_none|e('js') }}' }
  };
  var K = {
    calls: '{{ ui_kpi_calls|e('js') }}', calls_sub: '{{ ui_kpi_calls_sub|e('js') }}', conn: '{{ ui_kpi_connections|e('js') }}', conn_sub: '{{ ui_kpi_connections_sub|e('js') }}',
    changes: '{{ ui_kpi_changes|e('js') }}', changes_sub: '{{ ui_kpi_changes_sub|e('js') }}', rolled: '{{ ui_kpi_rolled|e('js') }}', rolled_sub: '{{ ui_kpi_rolled_sub|e('js') }}',
    last: '{{ ui_last_call|e('js') }}', last_ai: '{{ ui_last_ai|e('js') }}', never: '{{ ui_never|e('js') }}', top: '{{ ui_top_tools|e('js') }}', no_activity: '{{ ui_no_activity|e('js') }}', failed: '{{ ui_kpi_failed_logins|e('js') }}', recent_none: '{{ text_no_changes|e('js') }}'
  };
  var ACTN = { bulk_update_products: '{{ ui_did_bulk_update_products|e('js') }}', cancel_mail: '{{ ui_did_cancel_mail|e('js') }}', content_guidelines: '{{ ui_did_content_guidelines|e('js') }}', copy_product: '{{ ui_did_copy_product|e('js') }}', create_order: '{{ ui_did_create_order|e('js') }}', create_upload_link: '{{ ui_did_create_upload_link|e('js') }}', db_schema: '{{ ui_did_db_schema|e('js') }}', delete_image: '{{ ui_did_delete_image|e('js') }}', edit_image: '{{ ui_did_edit_image|e('js') }}', export_data: '{{ ui_did_export_data|e('js') }}', get_content: '{{ ui_did_get_content|e('js') }}', get_customer: '{{ ui_did_get_customer|e('js') }}', get_order: '{{ ui_did_get_order|e('js') }}', get_product: '{{ ui_did_get_product|e('js') }}', get_settings: '{{ ui_did_get_settings|e('js') }}', import_data: '{{ ui_did_import_data|e('js') }}', import_image: '{{ ui_did_import_image|e('js') }}', list_categories: '{{ ui_did_list_categories|e('js') }}', list_changes: '{{ ui_did_list_changes|e('js') }}', list_customers: '{{ ui_did_list_customers|e('js') }}', list_images: '{{ ui_did_list_images|e('js') }}', list_mails: '{{ ui_did_list_mails|e('js') }}', list_orders: '{{ ui_did_list_orders|e('js') }}', list_products: '{{ ui_did_list_products|e('js') }}', list_prompts: '{{ ui_did_list_prompts|e('js') }}', list_vouchers: '{{ ui_did_list_vouchers|e('js') }}', low_stock: '{{ ui_did_low_stock|e('js') }}', manage_customer: '{{ ui_did_manage_customer|e('js') }}', manage_records: '{{ ui_did_manage_records|e('js') }}', manage_review: '{{ ui_did_manage_review|e('js') }}', prepare_mail: '{{ ui_did_prepare_mail|e('js') }}', run_prompt: '{{ ui_did_run_prompt|e('js') }}', sales_report: '{{ ui_did_sales_report|e('js') }}', save_content: '{{ ui_did_save_content|e('js') }}', send_mail: '{{ ui_did_send_mail|e('js') }}', set_images: '{{ ui_did_set_images|e('js') }}', sql_query: '{{ ui_did_sql_query|e('js') }}', store_info: '{{ ui_did_store_info|e('js') }}', undo_changes: '{{ ui_did_undo_changes|e('js') }}', update_order_status: '{{ ui_did_update_order_status|e('js') }}', update_product: '{{ ui_did_update_product|e('js') }}', update_settings: '{{ ui_did_update_settings|e('js') }}' }; // what the AI did, in the owner's words, for the overview
  var ICON = { trash: '[[i:trash]]', eye: '[[i:eye]]', pen: '[[i:pen]]', eyeoff: '[[i:eyeoff]]', lock: '[[i:lock]]', list: '[[i:list]]', table: '[[i:table]]', check: '[[i:check]]', next: '[[i:next]]' };
  var ESC = {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'};
  function esc(t) { return String(t == null ? '' : t).replace(/[&<>"']/g, function (c) { return ESC[c]; }); } // quotes too: values also go into attributes
  function fmt(s, a, b) { return String(s).replace('%1', a).replace('%2', b).replace('%d', a).replace('%s', a); }

  // ================= navigation
  var HIST_ALIASES = { '#tab-changes': 'changes', '#tab-log': 'log' };
  function openTab(href) {
    var hist = HIST_ALIASES[href];
    if (hist) { href = '#tab-history'; }
    if (!$(href).length) { return; }
    $('#mcp-main-tabs a').removeClass('active').filter('[href="' + href + '"]').addClass('active');
    $('.mcp-pane').removeClass('active'); $(href).addClass('active');
    try { localStorage.setItem('mcp-tab', href); } catch (err) {}
    if (hist) { openHist(hist); }
    $(document).trigger('mcp:tab', [href]);
  }
  $('#mcp-main-tabs').on('click', 'a', function (e) { e.preventDefault(); openTab($(this).attr('href')); });
  $(document).on('click', '[data-goto]', function (e) { e.preventDefault(); openTab($(this).data('goto')); var sec = $(this).data('sec'); if (sec) { activeSection = sec; $('#acc-search').val(''); rerender(); } window.scrollTo(0, 0); });
  function openHist(which) {
    $('#hist-nav button').removeClass('active').filter('[data-hist="' + which + '"]').addClass('active');
    $('.mcp-hist').removeClass('active'); $('#hist-' + which).addClass('active');
    $(document).trigger('mcp:hist', [which]);
  }
  $('#hist-nav').on('click', 'button', function () { openHist($(this).data('hist')); });

  // ================= small helpers
  function random(len) {
    var bytes = new Uint8Array(len); (window.crypto || window.msCrypto).getRandomValues(bytes);
    var a = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789', out = '';
    for (var i = 0; i < bytes.length; i++) { out += a[bytes[i] % a.length]; }
    return out;
  }
  function copyText(text, $btn) {
    if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(text); } else { var $t = $('<textarea>').val(text).appendTo('body').select(); try { document.execCommand('copy'); } catch (e) {} $t.remove(); }
    if ($btn) { var old = $btn.html(); $btn.html('<i class="' + ICON.check + '"></i> {{ ui_copied|e('js') }}'); setTimeout(function () { $btn.html(old); }, 1400); }
  }
  // token and secret address rows have the same buttons: eye, Generate, Copy, Delete; each is on only when it has something to act on
  var TOKEN_SET = {{ token_set ? 'true' : 'false' }};
  function tokenButtons() { var v = $('#input-token').val() || ''; $('#mcp-token-show, #mcp-token-copy').prop('disabled', !v); $('#mcp-token-clear').prop('disabled', !v && !(TOKEN_SET && $('#input-token-clear').val() !== '1')); }
  $('#mcp-token-clear').on('click', function () { $('#input-token-clear').val('1'); $('#input-token').val('').attr({ placeholder: TOKEN_SET ? '{{ text_token_removed_on_save|e('js') }}' : '', type: 'password' }); $('#mcp-token-show i').addClass('fa-eye').removeClass('fa-eye-slash'); $('#mcp-token-new').hide(); tokenButtons(); dirty(); });
  $('#mcp-token-copy').on('click', function () { var v = $('#input-token').val(); if (v) { copyText(v, $(this)); } });
  $('#mcp-secret-clear').on('click', function () { $('#input-url-secret').val('').attr('type', 'password').trigger('input'); $('[data-mcp-toggle="#input-url-secret"] i').addClass('fa-eye').removeClass('fa-eye-slash'); dirty(); });
  $('[data-mcp-generate]').on('click', function () {
    var $i = $($(this).data('mcp-generate'));
    $i.val(($(this).data('mcp-prefix') || '') + random(parseInt($(this).data('mcp-length'), 10) || 24)).attr('type', 'text');
    $('[data-mcp-toggle="' + $(this).data('mcp-generate') + '"] i').removeClass('fa-eye').addClass('fa-eye-slash');
    if ($(this).data('mcp-generate') === '#input-token') { $('#mcp-token-new').show(); $('#input-token-clear').val('0'); } // a new token can be shown and hidden until the save; a saved one is kept only as a hash
    $i.trigger('input');
    dirty();
  });
  $('#input-token').on('input', tokenButtons); // a saved token is kept only as a hash: there is nothing to show until one is typed or generated
  // the store's own MCP address: a random path, made at install; a new one revokes every connection on save
  $('#mcp-path-gen').on('click', function () {
    var p = 'mcp-' + random(12).toLowerCase();
    $('#input-path').val(p); $('#mcp-endpoint, #mcp-endpoint-conn').val($('#mcp-endpoint').data('base') + p); $('#mcp-path-note').show();
    dirty();
  });
  // the secret URL: a changed one revokes every connection on save, the owner is told before
  // OpenCart 4 writes save errors into #error-* after the answer: a folded "Advanced settings" opens to show them
  $(document).ajaxComplete(function () { if ($('#mcp-keys .mcp-err').filter(function () { return $.trim($(this).text()) !== ''; }).length) { $('#mcp-keys').prop('open', true); } });
  function secretLine() { var $i = $('#input-url-secret'), v = $.trim($i.val() || ''); $('#mcp-secret-copy, #mcp-secret-clear').prop('disabled', v === ''); }
  $('#input-url-secret').on('input', function () { var was = String($(this).data('saved') || ''); $('#mcp-secret-note').toggle(was !== '' && $.trim(this.value) !== was); secretLine(); });
  $('#mcp-secret-copy').on('click', function () { var v = $.trim($('#input-url-secret').val()); if (v) { copyText($('#input-url-secret').data('base') + v, $(this)); } });
  $('[data-mcp-toggle]').on('click', function () { var $i = $($(this).data('mcp-toggle')), show = $i.attr('type') === 'password'; $i.attr('type', show ? 'text' : 'password'); $(this).find('i').toggleClass('fa-eye', !show).toggleClass('fa-eye-slash', show); secretLine(); });
  $('[data-mcp-copy]').on('click', function () { copyText($($(this).data('mcp-copy')).val(), $(this)); });
  $('.mcp-connect').on('click', function () { if ($(this).data('copy')) { copyText($(this).data('copy'), $(this)); } window.open($(this).data('url'), '_blank', 'noopener'); }); // Claude gets the name and address from the link itself
  $('[data-copy-text]').on('click', function () { copyText(String($(this).data('copy-text')), $(this)); });
  // the short address is asked from the server itself: 404 from the hosting = the .htaccess rewrite rules are off (the store's own SEO links fail too)
  if ({{ pretty_urls ? 'true' : 'false' }}) { $.getJSON('{{ check_url|raw }}'.replace(/&amp;/g, '&'), function (j) { if (j && j.level === 'bad') { $('#ov-addr-problem').show().find('.t').text(j.text); } }); }
  $(document).on('click', 'dialog [data-close]', function () { var d = $(this).closest('dialog')[0]; d.close ? d.close() : d.removeAttribute('open'); });
  function showDialog(d) { if (d.showModal) { d.showModal(); } else { d.setAttribute('open', ''); } }

  // ================= deletion (Settings, red section): clear the data, or remove the module with everything
  var DEL = { wipe: { title: '{{ ui_wipe_title|e('js') }}', text: '{{ ui_wipe_text|e('js') }}', sure: '{{ ui_wipe_sure|e('js') }}', go: '{{ ui_wipe_button|e('js') }}', url: '{{ wipe_url|raw }}'.replace(/&amp;/g, '&') },
              remove: { title: '{{ ui_remove_title|e('js') }}', text: '{{ ui_remove_text|e('js') }}', sure: '{{ ui_remove_sure|e('js') }}', go: '{{ ui_remove_button|e('js') }}', url: '{{ remove_url|raw }}'.replace(/&amp;/g, '&') },
              rows: '{{ ui_del_rows|e('js') }}', trash: '{{ ui_del_trash|e('js') }}', settings: '{{ ui_del_settings|e('js') }}', files: '{{ ui_del_files|e('js') }}', kept: '{{ ui_del_kept|e('js') }}' };
  var delMode = '';
  function delCheck() { $('#mcp-del-go').prop('disabled', !$('#mcp-del-sure').is(':checked')); }
  function delOpen(mode) {
    delMode = mode; var m = DEL[mode];
    $('#mcp-del-title').text(m.title); $('#mcp-del-text').text(m.text); $('#mcp-del-go span').text(m.go); $('#mcp-del-err').hide();
    $('#mcp-del-sure-text').text(m.sure); $('#mcp-del-sure').prop('checked', false);
    $('#mcp-del-list').html('<li>…</li>'); delCheck(); showDialog(document.getElementById('mcp-del-dialog'));
    $.getJSON('{{ wipe_preview_url|raw }}'.replace(/&amp;/g, '&'), function (j) {
      var rows = 0; $.each(j.rows || {}, function (t, n) { rows += n; });
      var items = [fmt(DEL.rows, rows), fmt(DEL.trash, (j.trash || {}).files || 0, (((j.trash || {}).bytes || 0) / 1048576).toFixed(1)), DEL.settings];
      if (mode === 'remove') { items.push(DEL.files); }
      $('#mcp-del-list').html(items.map(function (x) { return '<li>' + esc(x) + '</li>'; }).join('') + '<li class="text-muted">' + esc(DEL.kept) + '</li>');
    });
  }
  $('#mcp-trash-clear').on('click', function () {
    if (!confirm('{{ ui_trash_confirm|e('js') }}')) { return; }
    var $b = $(this).prop('disabled', true);
    $.ajax({ url: '{{ clear_trash_url|raw }}'.replace(/&amp;/g, '&'), type: 'post', data: {}, dataType: 'json', success: function (j) { if (j.error) { alert(j.error); $b.prop('disabled', false); return; } $('#mcp-trash-info').text(j.success); } });
  });
  $('#mcp-wipe-open').on('click', function () { delOpen('wipe'); });
  $('#mcp-remove-open').on('click', function () { delOpen('remove'); });
  $('#mcp-del-sure').on('change', delCheck);
  $('#mcp-del-go').on('click', function () {
    var $b = $(this).prop('disabled', true), data = { confirm: 1 };
    // read as text: a PHP notice the host prints before the JSON must not turn a done removal into an error
    $.ajax({ url: DEL[delMode].url, type: 'post', data: data, dataType: 'text',
      success: function (t) {
        var j; try { j = JSON.parse(String(t).slice(String(t).indexOf('{'))); } catch (err) { j = { error: '{{ error_permission|e('js') }}' }; }
        if (j.error) { $('#mcp-del-err').text(j.error).show(); $b.prop('disabled', false); return; }
        alert(j.success); isDirty = false;
        if (j.redirect) { location.href = j.redirect; } else { location.reload(); }
      },
      error: function () { $('#mcp-del-err').text('{{ error_permission|e('js') }}').show(); $b.prop('disabled', false); } });
  });

  // ================= unsaved changes bar
  var isDirty = false;
  function dirty() { if (!isDirty) { isDirty = true; $('#mcp-savebar').addClass('show'); } }
  $('#form-module').on('change input', 'input:not([readonly]):not(#acc-search):not([id^=lf-]), select:not([id^=lf-]), textarea', function () { if (!$(this).closest('#tab-prompts, #tab-history, .mcp-conns').length) { dirty(); } }); // the prompts tab saves on its own, history filters and connection names are not settings
  $('#mcp-discard').on('click', function () { isDirty = false; location.reload(); });
  // Enter in a text field (search, a number, a name) must not post the whole settings form: the Save button does that
  $('#form-module').on('keydown', 'input:not([type=submit]):not([type=checkbox]):not([type=radio])', function (e) { if (e.key === 'Enter') { e.preventDefault(); } });
  window.addEventListener('beforeunload', function (e) { if (isDirty) { e.preventDefault(); e.returnValue = ''; } });
  $('#input-status').on('change', function () { $('#ov-status').toggleClass('on', this.checked); });

  // ================= access by admin sections
  var state = {}, subByKey = {}, tableSection = {}, activeSection = null;
  window.__mcpAccess = { state: state };
  $.each(ACC.sections, function (i, sec) {
    $.each(sec.subs, function (j, sub) {
      sub.section = sec; subByKey[sub.key] = sub;
      $.each(sub.tables, function (k, tb) { state[tb.table] = { level: tb.level, columns: tb.columns.slice(), hidden: tb.hidden.slice(), max: tb.max }; tableSection[tb.table] = sec.key; });
    });
  });
  function cap(level, max) { return LV.indexOf(level) > LV.indexOf(max) ? max : level; }
  function subLevel(sub) {
    var lv = null, mixed = false;
    $.each(sub.tables, function (i, tb) { var l = state[tb.table].level; if (lv === null) { lv = l; } else if (lv !== l) { mixed = true; } });
    return { level: mixed ? null : lv, mixed: mixed, top: sub.tables.reduce(function (m, tb) { return Math.max(m, LV.indexOf(state[tb.table].level)); }, 0) };
  }
  function subMax(sub) { return sub.tables.reduce(function (m, tb) { return LV.indexOf(tb.max) > LV.indexOf(m) ? tb.max : m; }, 'none'); }
  function seg(name, current, max, disabledAll, customKey) {
    var h = '<div class="mcp-seg' + (customKey !== undefined ? ' c5' : '') + '" role="radiogroup">';
    $.each(['read', 'write', 'full', 'custom', 'none'], function (i, lv) { // "Blocked" sits at the end, like "Block all"
      if (lv === 'custom') { // set by hand in "Details": lit then, and a way into the dialog
        if (customKey !== undefined) { h += '<button type="button" class="seg-custom' + (current === 'custom' ? ' on' : '') + '" data-custom="' + customKey + '"' + (disabledAll ? ' disabled' : '') + '>' + esc(T.custom) + '</button>'; }
        return;
      }
      var id = name + '-' + lv, dis = disabledAll || LV.indexOf(lv) > LV.indexOf(max);
      h += '<input type="radio" name="' + name + '" id="' + id + '" value="' + lv + '"' + (current === lv ? ' checked' : '') + (dis ? ' disabled' : '') + '/><label for="' + id + '"><span class="mcp-dot lv-' + lv + '"></span>' + T[lv] + '</label>';
    });
    return h + '</div>';
  }
  /** A part set by hand in "Details": hidden fields, only some fields changeable, or kinds of records on different levels. */
  function isCustom(sub) {
    var open = sub.tables.filter(function (tb) { return tb.max !== 'none'; }), same = false;
    var limited = open.some(function (tb) { var st = state[tb.table]; return st.hidden.length || (st.level === 'write' && st.columns.length); });
    LV.forEach(function (lv) { if (open.every(function (tb) { return state[tb.table].level === cap(lv, tb.max); })) { same = true; } });
    if (same && !limited) { return false; }
    // a standard preset may set the kinds of a part differently on purpose: that is not "custom"
    PRESET_CACHE = PRESET_CACHE || ['read', 'write', 'all'].map(presetLevels);
    return !PRESET_CACHE.some(function (want) { return open.every(function (tb) { var st = state[tb.table], w = want[tb.table]; return st.level === w.level && !st.hidden.length && (st.level !== 'write' || sameCols(st.columns, w.columns)); }); });
  }
  var PRESET_CACHE = null;
  // ---- what the owner sees: menu items with their admin-form fields; power users also see tables and technical fields
  var fieldMeta = {}, tableInfo = {};
  $.each(ACC.sections, function (i, sec) { $.each(sec.subs, function (j, sub) { $.each(sub.tables, function (k, tb) { tableInfo[tb.table] = tb; fieldMeta[tb.table] = fieldMeta[tb.table] || {}; $.each(tb.fields, function (m, f) { fieldMeta[tb.table][f.name] = f; }); }); }); });
  function subVisible(sub) { return (sub.menu !== false && subMax(sub) !== 'none' && !sub.section.locked); }
  function visibleSections() {
    return ACC.sections.map(function (sec) { return { sec: sec, subs: sec.subs.filter(subVisible) }; }).filter(function (x) { return x.subs.length && !x.sec.locked; });
  }
  /** Field groups of a menu item: the admin form's tabs (owner view) or one group per table (power view). Each field: table, column, label, meta. */
  function subGroups(sub) {
    var out = [];
    if (sub.groups && sub.groups.length) {
      $.each(sub.groups, function (i, g) {
        var fields = [];
        $.each(g.fields, function (k, f) { var m = (fieldMeta[f.table] || {})[f.column]; if (m && !f.technical && state[f.table] && !m.secret) { fields.push({ table: f.table, column: f.column, label: f.label || m.label, meta: m, required: !!f.required }); } });
        if (fields.length) { out.push({ title: g.title, fields: fields }); }
      });
      if (out.length) { return out; }
    }
    // no admin form to follow: power users get every table, everyone else the main record of the menu item only
    $.each(sub.tables.slice(0, 1), function (i, tb) {
      var fields = [];
      $.each(tb.fields, function (k, f) { if (!f.secret && !f.technical) { fields.push({ table: tb.table, column: f.name, label: f.label, meta: f }); } });
      if (fields.length) { out.push({ title: sub.title || tb.table, table: tb.table, fields: fields }); }
    });
    return out;
  }
  function fieldState(f) {
    var st = state[f.table], hidden = st.hidden.indexOf(f.column) !== -1;
    return { hidden: hidden, edit: !hidden && (st.level === 'full' || (st.level === 'write' && (st.columns.length === 0 || st.columns.indexOf(f.column) !== -1))), canEdit: st.level === 'write' || st.level === 'full', none: st.level === 'none' };
  }
  /** Fields of a part: the row button shows exactly the number of field rows its dialog lists. */
  function countFields(sub) { return kindsOf(sub).reduce(function (n, k) { return n + rows(k).length; }, 0); }
  function escRe(t) { return String(t).replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }
  function hl(text, q) { var e = esc(text); return q ? e.replace(new RegExp('(' + escRe(esc(q)) + ')', 'ig'), '<mark>$1</mark>') : e; }
  function card(sub, showSection, q) {
    var sl = subLevel(sub), max = subMax(sub), locked = sub.section.locked || max === 'none', n = countFields(sub), custom = isCustom(sub), more = '';
    if (q) {
      var hits = [];
      $.each(subGroups(sub), function (i, g) { $.each(g.fields, function (k, f) { if (String(f.label).toLowerCase().indexOf(q) !== -1) { hits.push(hl(f.label, q)); } else if (DBN && (f.table + '.' + f.column).indexOf(q) !== -1) { hits.push(hl(f.table + '.' + f.column, q)); } }); });
      if (hits.length) { more = '<p class="hits">' + esc(T.found_in_fields) + ' ' + hits.slice(0, 6).join(', ') + (hits.length > 6 ? ' …' : '') + '</p>'; }
    }
    var h = '<div class="mcp-sub-card' + (sl.top === 0 ? ' off' : '') + '" data-sub="' + sub.key + '"><div class="acc-row">';
    h += '<div class="nm">' + (showSection ? '<span class="sec-label">' + hl(sub.section.title, q) + ' ›</span>' : '') + '<b>' + hl(sub.title || sub.key, q) + '</b>' + (locked ? '<i class="' + ICON.lock + ' lk"></i>' : '') + (sub.desc ? '<span class="d1">' + hl(sub.desc, q) + '</span>' : '') + '</div>';
    h += seg('sub_' + sub.key.replace('.', '_'), custom ? 'custom' : (sl.mixed ? LV[sl.top] : sl.level), max, locked, sub.key);
    h += '<button type="button" class="acl-columns" data-fields="' + sub.key + '"' + (locked ? ' disabled' : '') + '><span><i class="' + ICON.list + '"></i> ' + esc(T.fields) + '</span> <span class="n">' + n + '</span></button></div>';
    return h + (more ? '<div class="more">' + more + '</div>' : '') + '</div>';
  }
  /** Levels of the visible parts of a section: [[level, count], ...] from Full down to Closed. */
  function secZones(subs) {
    var n = { none: 0, read: 0, write: 0, full: 0, custom: 0 };
    $.each(subs, function (i, sub) { n[isCustom(sub) ? 'custom' : LV[subLevel(sub).top]]++; });
    return ['full', 'write', 'custom', 'read', 'none'].filter(function (lv) { return n[lv] > 0; }).map(function (lv) { return [lv, n[lv]]; });
  }
  function secSummary(sec, subs) {
    subs = subs || sec.subs;
    var tops = subs.map(function (s) { return subLevel(s).top; }), hi = Math.max.apply(null, tops.concat([0])), same = tops.every(function (t) { return t === tops[0]; });
    return { level: same ? LV[tops[0] || 0] : 'mixed', top: hi, editable: tops.filter(function (t) { return t >= 2; }).length, open: tops.filter(function (t) { return t >= 1; }).length, zones: secZones(subs) };
  }
  function renderSections() {
    var h = '', vis = visibleSections();
    if (!vis.some(function (x) { return x.sec.key === activeSection; }) && vis.length) { activeSection = vis[0].sec.key; }
    $.each(vis, function (i, x) {
      var s = secSummary(x.sec, x.subs);
      h += '<a href="#" data-sec="' + x.sec.key + '" class="' + (x.sec.key === activeSection ? 'active' : '') + '">' + esc(x.sec.title || x.sec.key) + (x.sec.locked ? ' <i class="' + ICON.lock + ' lk"></i>' : '') + '</a>';
    });
    $('#acc-sections').html(h);
  }
  function renderBody(keepOpen) {
    var q = $.trim($('#acc-search').val() || '').toLowerCase(), h = '', any = false, open = keepOpen || {}, vis = visibleSections();
    if (q) {
      $.each(vis, function (i, x) { $.each(x.subs, function (j, sub) {
        var hay = (sub.title + ' ' + sub.desc + ' ' + x.sec.title).toLowerCase();
        $.each(subGroups(sub), function (k, g) { $.each(g.fields, function (m, f) { hay += ' ' + String(f.label).toLowerCase(); }); });
        if (hay.indexOf(q) !== -1) { h += card(sub, true, q); any = true; }
      }); });
      $('#acc-locked-note').hide();
    } else {
      var cur = vis.filter(function (x) { return x.sec.key === activeSection; })[0] || vis[0];
      if (cur) { $.each(cur.subs, function (j, sub) { h += card(sub, false, ''); any = true; }); }
      $('#acc-locked-note').toggle(!!(cur && cur.sec.locked));
    }
    $('#acc-body').html(any ? h : '<div class="mcp-card mcp-empty">' + esc(T.no_matches) + '</div>');
  }
  function rerender() { renderSections(); renderBody(); renderOverviewAccess(); presetMark(); }
  activeSection = ACC.sections.length ? ACC.sections[0].key : null;
  $('#acc-sections').on('click', 'a', function (e) { e.preventDefault(); activeSection = $(this).data('sec'); $('#acc-search').val(''); rerender(); });
  $('#acc-search').on('input', function () { renderBody(); });
  $('#acc-body').on('change', 'input[type=radio], select', function () {
    var name = this.name, lv = this.value;
    if (name.indexOf('sub_') === 0) {
      var sub = subByKey[$(this).closest('.mcp-sub-card').data('sub')];
      $.each(sub.tables, function (i, tb) { state[tb.table].level = cap(lv, tb.max); });
    } else if (name.indexOf('tbl_') === 0) {
      var t = name.substr(4); state[t].level = cap(lv, state[t].max);
    }
    dirty(); rerender();
  });
  // ---- presets: the button of the preset the access matches is lit (also after saving); any own change makes "My settings"
  function presetLevels(preset) {
    var out = {};
    $.each(state, function (table, st) {
      if (preset === 'none') { out[table] = { level: 'none', columns: [] }; return; }
      var pr = PRESETS[preset] || PRESETS.write, rule = pr.rules[table];
      var lv = rule ? rule.level : (tableSection[table] === 'other' ? 'none' : pr['default']);
      lv = cap(lv, st.max);
      out[table] = { level: lv, columns: lv === 'write' && rule ? rule.columns.slice() : [] };
    });
    return out;
  }
  function snapshot() { var o = {}; $.each(state, function (t, st) { o[t] = { level: st.level, columns: st.level === 'write' ? st.columns.slice() : [], hidden: st.hidden.slice() }; }); return o; }
  function sameCols(a, b) { return (a || []).slice().sort().join(',') === (b || []).slice().sort().join(','); }
  function matchedPreset() {
    var names = ['read', 'write', 'all', 'none'];
    for (var i = 0; i < names.length; i++) {
      var want = presetLevels(names[i]), ok = true;
      $.each(state, function (t, st) { var w = want[t]; if (st.level !== w.level || st.hidden.length || (st.level === 'write' && !sameCols(st.columns, w.columns))) { ok = false; return false; } });
      if (ok) { return names[i]; }
    }
    return null;
  }
  function presetMark() {
    var m = matchedPreset();
    if (!m) { CUSTOM = snapshot(); } // the current access is the owner's own: keep it as "My settings"
    $('.mcp-presets [data-acl-preset]').each(function () { $(this).toggleClass('on', $(this).data('acl-preset') === m); });
    $('#acl-custom').prop('disabled', !CUSTOM).toggleClass('on', !m && !!CUSTOM);
  }
  $('[data-acl-preset]').on('click', function () {
    var want = presetLevels($(this).data('acl-preset'));
    $.each(state, function (table, st) { st.hidden = []; st.level = want[table].level; st.columns = want[table].columns; });
    dirty(); rerender();
  });
  $('#acl-custom').on('click', function () {
    if (!CUSTOM) { return; }
    $.each(state, function (table, st) { var c = CUSTOM[table]; if (c) { st.level = cap(c.level, st.max); st.columns = (c.columns || []).slice(); st.hidden = (c.hidden || []).slice(); } });
    dirty(); rerender();
  });

  // ---- access matrix of a part: kinds of records (rows) x see / change / delete (columns); a kind opens to its fields
  var dlg = document.getElementById('mcp-dialog'), dlgSub = null, AM = null, DBN = !!ACC.db_names; // the owner may ask for the database names under the labels
  var LVI = { none: 0, read: 1, write: 2, full: 3 };
  var IN_PARENT = { product_related: 1, product_filter: 1, product_image: 1, product_reward: 1, order_product: 1, order_option: 1, order_total: 1, order_shipment: 1, order_subscription: 1,
    customer_history: 1, customer_transaction: 1, customer_reward: 1, customer_ip: 1, customer_activity: 1, customer_online: 1, customer_wishlist: 1, customer_search: 1 }; // a customer's own tabs, not records of the address above them // fields of the product's own tabs (Links, Image, Reward Points), not records of their own
  var TWIN = { product_reward: 1 }; // reward points per customer group go with the product's points: one row gives both
  function rows(k) { return k.fields.filter(function (f) { return !f.twin; }); }
  function kindsOf(sub) {
    var list = [], byTable = {};
    sub.tables.forEach(function (tb, i) {
      if (tb.max === 'none') { return; }
      if (!list.length || (tb.label && !IN_PARENT[tb.table])) { var k = { key: tb.table, title: !list.length ? (sub.title || tb.label || tb.table) : tb.label, tables: [tb.table], max: tb.max, fields: [] }; list.push(k); byTable[tb.table] = k; }
    });
    if (!list.length) { return list; }
    var cur = list[0]; // a table without a name of its own belongs to the kind above it (option values to options); the links of a product to the product
    sub.tables.forEach(function (tb) {
      if (tb.max === 'none') { return; }
      if (byTable[tb.table]) { cur = byTable[tb.table]; return; }
      if (IN_PARENT[tb.table]) { cur = list[0]; }
      byTable[tb.table] = cur; cur.tables.push(tb.table);
    });
    $.each(subGroups(sub), function (i, g) {
      $.each(g.fields, function (j, f) {
        var tb = tableInfo[f.table];
        if (!byTable[f.table] && tb && tb.max !== 'none') { // a field the form shows from another part's table (the SEO URL of the SEO tab): a kind of its own, the same access as in that part
          byTable[f.table] = { key: f.table, title: tb.label || f.table, tables: [f.table], max: tb.max, fields: [] }; list.push(byTable[f.table]);
        }
        var k = byTable[f.table]; if (k) { k.fields.push($.extend({ heading: g.title, gi: i }, f)); }
      });
    });
    var tbl = {}; sub.tables.forEach(function (tb) { tbl[tb.table] = tb; });
    list.forEach(function (k) { // two fields of one group with the same name: one row for both (Points), or say whose the second is ("Додаткові фото · Image")
      var seen = {};
      k.fields.forEach(function (f) {
        var key = f.heading + '|' + f.label;
        if (seen[key] && TWIN[f.table]) { f.twin = seen[key].table + '.' + seen[key].column; return; }
        if (seen[key] && tbl[f.table] && tbl[f.table].label) { f.label = tbl[f.table].label + ' · ' + f.label; }
        seen[key] = f;
      });
    });
    list.forEach(function (k) { // a kind the admin form does not show: its own columns
      if (k.fields.length) { return; }
      k.tables.forEach(function (t) { $.each((fieldMeta[t] || {}), function (c, m) { if (!m.secret && !m.technical) { k.fields.push({ heading: '', table: t, column: c, label: m.label, meta: m }); } }); });
    });
    return list;
  }
  function amOpen(sub) {
    var kinds = kindsOf(sub), lv = {}, fr = {}, fe = {};
    kinds.forEach(function (k) {
      lv[k.key] = state[k.key].level;
      k.fields.forEach(function (f) { var fs = fieldState(f), id = f.table + '.' + f.column; var off = lv[k.key] === 'none'; fr[id] = !off && !fs.hidden; fe[id] = !off && fs.edit && !f.meta.no_edit; });
      k.fields.forEach(function (f) { if (f.twin) { var id = f.table + '.' + f.column; fr[id] = fr[f.twin]; fe[id] = fe[f.twin] && !f.meta.no_edit; } });
    });
    var secs = amSections(kinds);
    return { kinds: kinds, secs: secs, lv: lv, fr: fr, fe: fe, open: secs.length ? (function () { var o = {}; o[secs[0].key] = true; return o; })() : {} };
  }
  /** The dialog shows the admin form's tabs in their order (General, Data, Links ... SEO), each a section that opens to its fields; a kind the form does not show is a section of its own. */
  function amSections(kinds) {
    var secs = [], byKey = {};
    kinds.forEach(function (k) {
      rows(k).forEach(function (f) {
        var key = f.gi !== undefined ? 'g' + f.gi : 'k-' + k.key;
        if (!byKey[key]) { byKey[key] = { key: key, title: f.gi !== undefined ? f.heading : k.title, order: f.gi !== undefined ? f.gi : 1000 + secs.length, fields: [], kinds: [] }; secs.push(byKey[key]); }
        byKey[key].fields.push($.extend({ kind: k.key }, f));
        if (byKey[key].kinds.indexOf(k.key) === -1) { byKey[key].kinds.push(k.key); }
      });
    });
    secs.forEach(function (sc) { // one tab, two kinds with a field of the same name (an option and its values): say whose the second is
      var seen = {};
      sc.fields.forEach(function (f) { var k = amKindIn(kinds, f.kind); if (seen[f.label] && seen[f.label] !== f.kind && k.title) { f.label = k.title + ' · ' + f.label; } seen[f.label] = seen[f.label] || f.kind; });
    });
    return secs.sort(function (a, b) { return a.order - b.order; });
  }
  function amKindIn(kinds, key) { return kinds.filter(function (k) { return k.key === key; })[0];
  }
  function amBox(cls, on, mixed, dis, label, data) {
    return '<input type="checkbox" class="' + cls + '"' + (on ? ' checked' : '') + (dis ? ' disabled' : '') + (mixed ? ' data-mixed="1"' : '') + ' aria-label="' + esc(label) + '"' + (data || '') + '/>';
  }
  function amRender() {
    var h = '<div class="am-table"><div class="am-row am-head"><span></span><span><i class="' + ICON.eye + '"></i><em>' + esc(T.read_col) + '</em></span><span><i class="' + ICON.pen + '"></i><em>' + esc(T.edit_col) + '</em></span><span><i class="' + ICON.trash + '"></i><em>' + esc(T.del_col) + '</em></span></div>';
    var all = { r: 0, e: 0, d: 0, n: AM.kinds.length };
    AM.kinds.forEach(function (k) { var l = LVI[AM.lv[k.key]]; if (l >= 1) { all.r++; } if (l >= 2) { all.e++; } if (l >= 3) { all.d++; } });
    h += '<div class="am-row am-all"><span>' + esc(T.all_kinds) + '</span><span>' + amBox('am-all-read', all.r === all.n, all.r && all.r < all.n, false, T.read_col) + '</span><span>' + amBox('am-all-edit', all.e === all.n, all.e && all.e < all.n, false, T.edit_col)
      + '</span><span>' + amBox('am-all-del', all.d === all.n, all.d && all.d < all.n, AM.kinds.every(function (k) { return k.max !== 'full'; }), T.del_col) + '</span></div>';
    AM.kinds.forEach(function (k) {
      var l = LVI[AM.lv[k.key]];
      if (l >= 1 && needAnchors(k)) { anchorsOf(k).forEach(function (a) { AM.fr[a.table + '.' + a.column] = true; }); } // the name of the record stays visible
      if (l >= 2) { k.fields.forEach(function (f) { if (f.required && !f.twin && !f.meta.secret && !f.meta.no_edit) { var id = f.table + '.' + f.column; AM.fr[id] = true; AM.fe[id] = true; } }); } // Edit and Full: the AI can add records, so the form's required fields stay seen and changeable (a field it never writes is not held)
    });
    AM.secs.forEach(function (sc) {
      var open = !!AM.open[sc.key], fr = 0, fe = 0, fen = 0, del = sc.kinds.map(amKind).filter(function (k) { return k.max === 'full'; });
      sc.fields.forEach(function (f) { var id = f.table + '.' + f.column, l = LVI[AM.lv[f.kind]], seen = l >= 1 && AM.fr[id]; if (seen) { fr++; } if (!f.meta.no_edit) { fen++; if (seen && l >= 2 && AM.fe[id]) { fe++; } } }); // the tab's "change" box is full only when every changeable field of the tab is changed
      var dn = del.filter(function (k) { return AM.lv[k.key] === 'full'; }).length;
      h += '<section class="am-kind am-sec' + (open ? ' open' : '') + '" data-sec="' + sc.key + '" data-kinds="' + sc.kinds.join(' ') + '"><div class="am-row am-k">'
        + '<button type="button" class="am-tog" aria-expanded="' + open + '"><i class="' + ICON.next + '"></i><span class="am-tt"><span class="am-tl"><b>' + esc(sc.title) + '</b><span class="n">' + sc.fields.length + '</span></span></span></button>'
        + '<span>' + amBox('am-s-read', fr === sc.fields.length, fr > 0 && fr < sc.fields.length, false, sc.title + ': ' + T.read_col) + '</span>'
        + '<span>' + amBox('am-s-edit', fen > 0 && fe === fen, fe > 0 && fe < fen, sc.fields.every(function (f) { return f.meta.no_edit; }), sc.title + ': ' + T.edit_col) + '</span>'
        + '<span>' + amBox('am-s-del', del.length > 0 && dn === del.length, dn > 0 && dn < del.length, !del.length, sc.title + ': ' + T.del_col) + '</span></div>';
      if (open) {
        h += '<div class="am-fields">';
        sc.fields.forEach(function (f) {
          var id = f.table + '.' + f.column, m = f.meta, k = amKind(f.kind), l = LVI[AM.lv[k.key]];
          var req = !!f.required && LVI[k.max] >= 2, reqLock = l >= 2 && req && !m.secret && !m.no_edit, locked = (isAnchor(k, id) && needAnchors(k)) || reqLock;
          var badges = (m.personal ? '<span class="mcp-badge pii">' + esc(T.pii) + '</span>' : '') + (m.money ? '<span class="mcp-badge money">' + esc(T.money) + '</span>' : '') + (m.masked ? '<span class="mcp-badge sec">' + esc(T.masked) + '</span>' : '') + (m.no_edit ? '<span class="mcp-badge tech">' + esc(T.no_edit) + '</span>' : '');
          h += '<div class="am-row am-f" data-id="' + id + '" data-kind="' + k.key + '"><span class="lbl"><span class="am-tt"><span class="am-tl">' + esc(f.label) + (req ? '<span class="am-req">*</span>' : '') + badges + '</span>' + (DBN ? '<span class="am-db">' + esc(id) + '</span>' : '') + '</span></span>'
            + '<span>' + amBox('am-f-read', l >= 1 && AM.fr[id], false, locked, f.label + ': ' + T.read_col) + '</span>'
            + '<span>' + amBox('am-f-edit', l >= 2 && AM.fe[id], false, !!m.no_edit || reqLock, f.label + ': ' + T.edit_col) + '</span>'
            + '<span>' + amBox('am-f-del', l >= 3, false, true, f.label + ': ' + T.del_col) + '</span></div>'; // deleting removes the whole record: shown per field, set on the section row
        });
        h += '</div>';
      }
      h += '</section>';
    });
    h += '</div>';
    $('#mcp-dialog-list').html(h);
    $('#mcp-dialog-list input[data-mixed]').each(function () { this.indeterminate = true; });
  }
  function amKind(key) { return AM.kinds.filter(function (k) { return k.key === key; })[0]; }
  function amSettle(k) { // the owner took the last own edit tick away: the required fields alone keep nothing to change, the kind goes back to "see"
    if (AM.lv[k.key] !== 'write') { return; }
    var own = k.fields.filter(function (f) { return !f.twin && !f.meta.no_edit && !(f.required && !f.meta.secret); }); // no_edit fields are never held, so they are not "own" either
    if (own.length && !own.some(function (f) { return AM.fe[f.table + '.' + f.column]; })) {
      AM.lv[k.key] = 'read';
      k.fields.forEach(function (f) { var id = f.table + '.' + f.column; AM.fe[id] = false; if (f.required && !f.meta.secret && !f.meta.no_edit && !isAnchor(k, id)) { AM.fr[id] = false; } }); // what the Edit rule held goes with it; the name stays while anything is seen
    }
  }
  function amSet(k, level) { // the ladder: delete includes change, change includes see
    AM.lv[k.key] = level;
    k.fields.forEach(function (f) {
      var id = f.table + '.' + f.column;
      if (level === 'none') { AM.fr[id] = false; AM.fe[id] = false; return; } // closed: no field is ticked, so ticking one later opens only that one
      if (LVI[level] >= 1 && !AM.fr[id]) { AM.fr[id] = true; }
      if (level === 'full') { AM.fe[id] = !f.meta.no_edit; }
      if (level === 'read') { AM.fe[id] = false; }
    });
  }
  $('#acc-body').on('click', '.seg-custom', function () { $(this).closest('.mcp-sub-card').find('.acl-columns[data-fields]').trigger('click'); });
  $('#acc-body').on('click', '[data-fields]', function () {
    dlgSub = subByKey[$(this).data('fields')];
    AM = amOpen(dlgSub);
    $('#mcp-dialog-title').text(T.fields_of.replace('%s', dlgSub.title));
    amRender();
    showDialog(dlg);
  });
  $('#mcp-dialog-list').on('click', '.am-tog', function () { var key = $(this).closest('.am-sec').data('sec'); AM.open[key] = !AM.open[key]; amRender(); });
  function amSec(key) { return AM.secs.filter(function (s) { return s.key === key; })[0]; }
  $('#mcp-dialog-list').on('change', '.am-s-read, .am-s-edit, .am-s-del', function () { // a section row sets the fields of its tab, as ticking each of them would
    var sc = amSec($(this).closest('.am-sec').data('sec')), on = this.checked, cls = this.className;
    sc.fields.forEach(function (f) {
      var id = f.table + '.' + f.column, k = f.kind;
      if (cls === 'am-s-read') { AM.fr[id] = on; if (!on) { AM.fe[id] = false; } else if (AM.lv[k] === 'none') { AM.lv[k] = 'read'; } }
      if (cls === 'am-s-edit' && !f.meta.no_edit) { AM.fe[id] = on; if (on) { AM.fr[id] = true; if (LVI[AM.lv[k]] < 2) { AM.lv[k] = 'write'; } } else if (AM.lv[k] === 'full') { AM.lv[k] = 'write'; } }
    });
    if (cls === 'am-s-del') { sc.kinds.map(amKind).forEach(function (k) { if (k.max === 'full') { amSet(k, on ? 'full' : 'write'); } }); }
    if (!on && (cls === 'am-s-edit' || cls === 'am-s-read')) { sc.kinds.map(amKind).forEach(amSettle); }
    if (cls === 'am-s-read' && !on) { sc.kinds.map(amKind).forEach(function (k) { if (!k.fields.some(function (f) { return AM.fr[f.table + '.' + f.column]; })) { amSet(k, 'none'); } }); } // nothing of the kind left to see: closed
    sc.fields.forEach(function (f) { var id = f.table + '.' + f.column; amKind(f.kind).fields.forEach(function (x) { if (x.twin === id) { var t = x.table + '.' + x.column; AM.fr[t] = AM.fr[id]; AM.fe[t] = AM.fe[id] && !x.meta.no_edit; } }); });
    amRender();
  });
  $('#mcp-dialog-list').on('change', '.am-all-read, .am-all-edit, .am-all-del', function () {
    var on = this.checked, cls = this.className;
    AM.kinds.forEach(function (k) {
      if (cls === 'am-all-read') { amSet(k, on ? (LVI[AM.lv[k.key]] >= 1 ? AM.lv[k.key] : 'read') : 'none'); if (on) { k.fields.forEach(function (f) { AM.fr[f.table + '.' + f.column] = true; }); } }
      if (cls === 'am-all-edit') { amSet(k, on ? (AM.lv[k.key] === 'full' ? 'full' : 'write') : (LVI[AM.lv[k.key]] >= 1 ? 'read' : 'none')); if (on) { k.fields.forEach(function (f) { var id = f.table + '.' + f.column; AM.fr[id] = true; AM.fe[id] = !f.meta.no_edit; }); } }
      if (cls === 'am-all-del' && k.max === 'full') { amSet(k, on ? 'full' : (LVI[AM.lv[k.key]] >= 2 ? 'write' : AM.lv[k.key])); }
    });
    amRender();
  });
  $('#mcp-dialog-list').on('change', '.am-f-read, .am-f-edit', function () {
    var id = $(this).closest('.am-f').data('id'), k = amKind($(this).closest('.am-f').data('kind')), on = this.checked;
    if ($(this).hasClass('am-f-read')) {
      AM.fr[id] = on; if (!on) { AM.fe[id] = false; } else if (AM.lv[k.key] === 'none') { AM.lv[k.key] = 'read'; }
    } else {
      AM.fe[id] = on;
      if (on) { AM.fr[id] = true; if (LVI[AM.lv[k.key]] < 2) { AM.lv[k.key] = 'write'; } } // the other fields keep their choice
      else if (AM.lv[k.key] === 'full') { AM.lv[k.key] = 'write'; } // "full" changes every field
    }
    if (!on) { amSettle(k); }
    k.fields.forEach(function (f) { if (f.twin === id) { var t = f.table + '.' + f.column; AM.fr[t] = AM.fr[id]; AM.fe[t] = AM.fe[id] && !f.meta.no_edit; } }); // one row, both fields
    amRender();
  });
  // the name of the record (name, model, code): when anything of a record is seen or changed, the AI must see which record it is.
  // The star (*) is a different thing: the form's own required mark, read from the store's admin form (f.required)
  var ANCHOR_COLS = ['name', 'title', 'model', 'code', 'firstname', 'lastname'];
  function needAnchors(k) { return k.fields.some(function (f) { return !f.twin && ANCHOR_COLS.indexOf(f.column) === -1 && AM.fr[f.table + '.' + f.column]; }); }
  function anchorsOf(k) { return k.fields.filter(function (f) { return !f.twin && (f.table === k.tables[0] || f.table === k.tables[0] + '_description') && ANCHOR_COLS.indexOf(f.column) !== -1 && !f.meta.secret; }); }
  function isAnchor(k, id) { return anchorsOf(k).some(function (a) { return a.table + '.' + a.column === id; }); }
  $('#mcp-dialog-close-all').on('click', function () { // close the whole part for the AI
    if (!AM) { return; }
    AM.kinds.forEach(function (k) { amSet(k, 'none'); });
    amRender();
  });
  $('#mcp-dialog-save').on('click', function () {
    AM.kinds.forEach(function (k) {
      var lv = AM.lv[k.key];
      k.tables.forEach(function (t) {
        var st = state[t], shown = k.fields.filter(function (f) { return f.table === t; });
        st.level = cap(lv, st.max);
        st.hidden = st.hidden.filter(function (c) { return !shown.some(function (f) { return f.column === c; }); }).concat(shown.filter(function (f) { return !AM.fr[t + '.' + f.column]; }).map(function (f) { return f.column; }));
        if (st.level === 'write') {
          var editable = shown.filter(function (f) { return !f.meta.no_edit && AM.fr[t + '.' + f.column]; }), on = editable.filter(function (f) { return AM.fe[t + '.' + f.column]; });
          st.columns = !shown.length || on.length === editable.length ? [] : on.map(function (f) { return f.column; });
          if (shown.length && !on.length && editable.length) { st.level = 'read'; st.columns = []; } // nothing left to change
        } else { st.columns = []; }
        if (shown.length && shown.every(function (f) { return !AM.fr[t + '.' + f.column]; })) { st.level = 'none'; st.columns = []; st.hidden = []; } // nothing left to see: closed
      });
    });
    dlg.close ? dlg.close() : dlg.removeAttribute('open');
    dirty(); rerender();
  });
  $(document).ajaxSuccess(function (e, xhr) { var j = xhr.responseJSON; if (j && j.rev) { $('#input-rev').val(j.rev); } if (j && j.revoked_notice) { isDirty = false; alert(j.revoked_notice); location.hash = '#tab-connections'; location.reload(); } }); // OpenCart 4 saves by AJAX: the form goes on from the new revision
  $('#form-module').on('submit', function () {
    var acl = {};
    $.each(state, function (table, st) { acl[table] = { level: st.level, columns: st.level === 'write' ? st.columns : [], hidden: st.hidden }; });
    $('#input-acl-json').val(JSON.stringify(acl));
    $('#input-acl-custom-json').val(CUSTOM ? JSON.stringify(CUSTOM) : '');
    isDirty = false; $('#mcp-savebar').removeClass('show');
  });

  // ================= overview
  function renderOverviewAccess() {
    var h = '';
    $.each(visibleSections(), function (i, x) {
      var sec = x.sec, s = secSummary(sec, x.subs);
      // one pill per access zone: "Edit 7 · View 3" instead of a vague "mixed"
      var pills = s.zones.map(function (z) { return '<span class="mcp-pill lv-' + z[0] + '">' + esc(z[0] === 'custom' ? T.custom : T[z[0]]) + (s.zones.length > 1 ? ' ' + z[1] : '') + '</span>'; }).join(' ');
      h += '<a href="#tab-access" data-goto="#tab-access" data-sec="' + sec.key + '"><span><b>' + esc(sec.title) + '</b><span class="sub">' + esc(fmt(T.subs_n, s.open, x.subs.length)) + '</span></span><span class="zones">' + pills + '</span><i class="' + ICON.next + ' go"></i></a>';
    });
    $('#ov-access').html(h);
    var m = matchedPreset() || 'custom'; // Safe green, Recommended blue, Full red, the owner's own purple, nothing black
    $('#ov-check-access').attr('class', 'pf-' + m).find('span').text(T.profile[m]);
  }
  function kpi(label, value, sub, alert) { return '<div class="mcp-kpi' + (alert ? ' is-warn' : '') + '"><div class="k">' + esc(label) + '</div><div class="v">' + esc(value) + '</div><div class="s">' + sub + '</div></div>'; }
  function renderKpis() {
    var s = STATS || {}, h = '';
    h += kpi(K.conn, s.connections_24h || 0, esc(fmt(K.conn_sub, s.connections || 0)), false);
    h += kpi(K.calls, s.calls_24h || 0, esc(fmt(K.calls_sub, s.errors_24h || 0, s.denied_24h || 0)) + (s.failed_logins_24h ? '<br><span style="color:#b45309">' + esc(fmt(K.failed, s.failed_logins_24h)) + '</span>' : ''), false);
    h += kpi(K.changes, s.changes_7d || 0, esc(fmt(K.changes_sub, s.rows_7d || 0)), false);
    h += kpi(K.rolled, s.rolled_back_7d || 0, esc(fmt(K.rolled_sub, s.conflicts_7d || 0)), !!s.conflicts_7d);
    $('#ov-kpis').html(h);
    // one bar a day with its number on top; a quiet day next to a busy one still shows (at least a sliver)
    var days = s.daily || [], max = Math.max.apply(null, days.map(function (d) { return d.calls; }).concat([1])), bars = '';
    $.each(days, function (i, d) {
      var ch = d.calls ? Math.max(Math.round(d.calls / max * 100), 4) : 0, ph = d.problems ? Math.max(Math.round(Math.min(d.problems, d.calls) / max * 100), 2) : 0;
      bars += '<div class="b' + (d.calls ? '' : ' z') + '"><em>' + (d.calls ? esc(d.calls) : '') + '</em><div class="c" style="height:' + Math.max(ch - ph, d.calls ? 2 : 0) + '%"></div>' + (ph ? '<div class="c p" style="height:' + ph + '%"></div>' : '') + '<small>' + esc(d.date.substr(8, 2) + '.' + d.date.substr(5, 2)) + '</small></div>';
    });
    $('#ov-chart').html(bars);
    var top = s.session_tools || [], tmax = Math.max.apply(null, top.map(function (t) { return t.calls; }).concat([1])), th = '', lc = s.last_call;
    th += '<div class="mcp-tdesc" style="margin:0 0 6px">' + esc(K.last) + ': ' + (lc ? esc(lc.date_added + (lc.client ? ' · ' + K.last_ai + ': ' + lc.client : '')) : esc(K.never)) + '</div>';
    if (top.length) { // the latest session of the AI (no pause over 20 min): the last five tools it used, with how often
      th += '<div class="mcp-tdesc" style="margin:8px 0 2px;font-weight:600">' + esc(K.top) + '</div>';
      $.each(top, function (i, t) { th += '<div class="tt"><span>' + esc(ACTN[t.tool] || String(t.tool).replace(/_/g, ' ')) + '</span><span>' + esc(t.calls) + '</span><div class="bar"><i style="width:' + Math.round(t.calls / tmax * 100) + '%"></i></div></div>'; });
    } else { th += '<div class="mcp-tdesc">' + esc(K.no_activity) + '</div>'; }
    $('#ov-top').html(th);
  }

  // ================= changes made by the AI (History): roll back, cancel a delayed deletion
  var chUrl = '{{ changes_url|raw }}'.replace(/&amp;/g, '&'), chDetUrl = '{{ change_details_url|raw }}'.replace(/&amp;/g, '&'), chRbUrl = '{{ rollback_url|raw }}'.replace(/&amp;/g, '&'), chCancelUrl = '{{ cancel_change_url|raw }}'.replace(/&amp;/g, '&'), chRbAllUrl = '{{ rollback_all_url|raw }}'.replace(/&amp;/g, '&');
  var CT = { pending: '{{ text_ch_pending|e('js') }}', rolled: '{{ text_ch_rolled_back|e('js') }}', conflict: '{{ text_ch_conflict|e('js') }}', cancelled: '{{ text_ch_cancelled|e('js') }}', applied: '{{ text_ch_applied|e('js') }}',
            rollback: '{{ text_rollback|e('js') }}', cancel: '{{ text_cancel_delete|e('js') }}', details: '{{ text_change_details|e('js') }}', confirm_rb: '{{ text_confirm_rollback|e('js') }}', confirm_force: '{{ text_confirm_force|e('js') }}', rb_blocked: '{{ ui_rb_blocked|e('js') }}', before: '{{ text_before|e('js') }}', after: '{{ text_after|e('js') }}',
            rows_n: '{{ ui_ch_rows_n|e('js') }}', by_admin: '{{ ui_ch_by_admin|e('js') }}', pending_at: '{{ ui_ch_pending_at|e('js') }}', conflict_n: '{{ ui_ch_conflict_n|e('js') }}', partial: '{{ ui_ch_partial|e('js') }}',
            rb_all: '{{ ui_ch_rb_all|e('js') }}', rb_all_q: '{{ ui_ch_rb_all_q|e('js') }}', none_open: '{{ ui_ch_none_open|e('js') }}', none_match: '{{ ui_ch_none_match|e('js') }}', none: '{{ text_no_changes|e('js') }}',
            col_date: '{{ column_date|e('js') }}', col_conn: '{{ ui_ch_connection|e('js') }}', col_what: '{{ ui_ch_what|e('js') }}', col_status: '{{ column_status|e('js') }}', no: '{{ ui_ch_no|e('js') }}', det_no: '{{ ui_ch_details_no|e('js') }}',
            csv_table: '{{ ui_ch_csv_table|e('js') }}', csv_action: '{{ ui_ch_csv_action|e('js') }}', csv_key: '{{ ui_ch_csv_key|e('js') }}', csv_field: '{{ ui_ch_csv_field|e('js') }}',
            sel_n: '{{ ui_ch_sel_n|e('js') }}', rb_q: '{{ ui_ch_rb_sel_q|e('js') }}', rb_done: '{{ ui_ch_rb_sel_done|e('js') }}' };
  // what the AI did, in the owner's words (the tool's name stays in the request log)
  var TOOLN = { update_product: '{{ ui_tool_update_product|e('js') }}', bulk_update_products: '{{ ui_tool_bulk_update_products|e('js') }}', copy_product: '{{ ui_tool_copy_product|e('js') }}', set_product_special: '{{ ui_tool_set_product_special|e('js') }}', remove_product_special: '{{ ui_tool_remove_product_special|e('js') }}',
    update_order_status: '{{ ui_tool_update_order_status|e('js') }}', create_order: '{{ ui_tool_create_order|e('js') }}', save_content: '{{ ui_tool_save_content|e('js') }}', set_images: '{{ ui_tool_set_images|e('js') }}', import_image: '{{ ui_tool_import_image|e('js') }}', delete_image: '{{ ui_tool_delete_image|e('js') }}',
    manage_records: '{{ ui_tool_manage_records|e('js') }}', manage_customer: '{{ ui_tool_manage_customer|e('js') }}', import_data: '{{ ui_tool_import_data|e('js') }}', manage_review: '{{ ui_tool_manage_review|e('js') }}', update_settings: '{{ ui_tool_update_settings|e('js') }}', upload: '{{ ui_tool_upload|e('js') }}', undo_changes: '{{ ui_tool_undo_changes|e('js') }}',
    cancel_pending_change: '{{ ui_tool_cancel_pending_change|e('js') }}', prepare_mail: '{{ ui_tool_prepare_mail|e('js') }}', send_mail: '{{ ui_tool_send_mail|e('js') }}', cancel_mail: '{{ ui_tool_cancel_mail|e('js') }}', run_prompt: '{{ ui_tool_run_prompt|e('js') }}' };
  function toolName(t) { return TOOLN[t] || String(t || '').replace(/_/g, ' '); }
  // what the owner calls the records, not the database tables: "product, product_description" -> "Товари"
  var TBLN = {}, TBLMAIN = {};
  $.each(ACC.sections, function (i, sec) { $.each(sec.subs, function (j, sub) { var main = sub.tables[0] ? (sub.tables[0].label || sub.title) : sub.title; $.each(sub.tables, function (k, tb) { TBLN[tb.table] = tb.label || main; }); }); });
  function tblNames(list) { var out = []; String(list || '').split(/,\s*/).forEach(function (t) { var n = TBLN[t] || (t.indexOf('mcp_') === 0 ? t.replace('mcp_image', '{{ ui_tbl_images|e('js') }}') : t); if (n && out.indexOf(n) === -1) { out.push(n); } }); return out.join(', '); }
  // one pager for both History tables: "Shown 1–50 of 1 234", pages, rows per page
  var PG = { shown: '{{ ui_pg_shown|e('js') }}', per: '{{ ui_pg_per|e('js') }}', prev: '{{ ui_pg_prev|e('js') }}', next: '{{ ui_pg_next|e('js') }}' };
  function pagerHtml(total, offset, limit, sizes) {
    sizes = sizes || [25, 50, 100];
    if (!total) { return ''; }
    var pages = Math.ceil(total / limit), cur = Math.floor(offset / limit) + 1, h = '', last = 0;
    var show = function (p) { return p === 1 || p === pages || Math.abs(p - cur) <= 1 || (cur <= 3 && p <= 4) || (cur >= pages - 2 && p >= pages - 3); };
    h += '<span>' + esc(PG.shown.replace('%1', offset + 1).replace('%2', Math.min(total, offset + limit)).replace('%3', total)) + '</span><span class="sp"></span>';
    h += '<nav class="pg"><button type="button" data-page="' + (cur - 1) + '"' + (cur <= 1 ? ' disabled' : '') + ' aria-label="' + esc(PG.prev) + '">‹</button>';
    for (var p = 1; p <= pages; p++) { if (!show(p)) { continue; } if (last && p - last > 1) { h += '<span class="gap">…</span>'; } h += '<button type="button" data-page="' + p + '"' + (p === cur ? ' class="on" aria-current="page"' : '') + '>' + p + '</button>'; last = p; }
    h += '<button type="button" data-page="' + (cur + 1) + '"' + (cur >= pages ? ' disabled' : '') + ' aria-label="' + esc(PG.next) + '">›</button></nav>';
    h += '<label class="per">' + esc(PG.per) + ' <select>' + sizes.map(function (n) { return '<option' + (n === limit ? ' selected' : '') + '>' + n + '</option>'; }).join('') + '</select></label>';
    return h;
  }
  function bindPager(sel, state, load) {
    $(sel).on('click', '[data-page]', function () { state.offset = (+$(this).data('page') - 1) * state.limit; load(); var $t = $(sel).closest('.mcp-hist'); if ($t.length && $t.offset().top < $(window).scrollTop()) { $('html,body').scrollTop($t.offset().top - 80); } });
    $(sel).on('change', 'select', function () { state.limit = +this.value; state.offset = 0; load(); });
  }
  // one row = one tool call of the AI: when, which connection, what it did, what became of it
  function pill(cls, text) { return '<span class="mcp-pill ' + cls + '">' + esc(text) + '</span>'; }
  function chWhen(d) { return '<b>' + esc(String(d || '').slice(0, 16)) + '</b>'; }
  function chNo(b) { return +b.batch_no ? '#' + (+b.batch_no) : '—'; }
  function chConn(b) { var name = b.conn_name || b.client || '—', sub = b.client && name.indexOf(b.client) === -1 ? b.client : ''; return '<b>' + esc(name) + '</b>' + (sub ? '<div class="mcp-tdesc">' + esc(sub) + '</div>' : ''); }
  function chWhat(b) { var t = toolName(b.tool), what = tblNames(b.tables), n = CT.rows_n.replace('%d', +b.changes); return '<b>' + esc(t || what) + '</b><div class="mcp-tdesc">' + esc(t ? what + ' · ' + n : n) + '</div>'; } // an old record without the tool's name: the records themselves
  function chWho(b) { var who = String(b.done_by || ''); if (who.indexOf('admin:') === 0) { who = CT.by_admin.replace('%s', who.slice(6)); } var when = b.date_done ? String(b.date_done).slice(0, 16) : '', t = [when, who].filter(Boolean).join(' · '); return t ? '<div class="mcp-tdesc">' + esc(t) + '</div>' : ''; }
  function chStatus(b) {
    var n = +b.changes, rb = +b.rolled_back, cf = +b.conflicts, cc = +b.cancelled;
    if (+b.pending) { return pill('y', CT.pending) + '<div class="mcp-tdesc">' + esc(CT.pending_at.replace('%s', String(b.execute_after || '').slice(11, 16))) + '</div>'; }
    if (cf) { return pill('r', CT.conflict) + '<div class="mcp-tdesc">' + esc(CT.conflict_n.replace('%1', cf).replace('%2', n)) + '</div>'; }
    if (rb + cc >= n) { return pill('n', rb ? CT.rolled : CT.cancelled) + chWho(b); }
    if (rb || cc) { return pill('n', CT.partial.replace('%1', rb + cc).replace('%2', n)) + chWho(b); }
    return pill('g', CT.applied);
  }
  function chCanRb(b) { return +b.applied > 0 || +b.conflicts > 0; }
  function chActions(b) { return '<button type="button" class="mcp-btn s ch-det">' + esc(CT.details) + '</button> ' + (+b.pending ? '<button type="button" class="mcp-btn s ch-cancel">' + esc(CT.cancel) + '</button> ' : '') + (chCanRb(b) ? '<button type="button" class="mcp-btn s d ch-rb">' + esc(CT.rollback) + '</button>' : ''); }
  function chRow(b, compact) {
    var sel = !!chSel[b.batch_id];
    return '<tr data-batch="' + esc(b.batch_id) + '" data-order="' + esc(b.date_added) + '|' + esc(b.batch_id) + '"' + (sel ? ' class="sel"' : '') + '>'
      + (compact ? '' : '<td class="ck">' + (chCanRb(b) && !+b.pending ? '<input type="checkbox" class="mcp-ck ch-ck"' + (sel ? ' checked' : '') + ' aria-label="' + esc(b.date_added) + '"/>' : '') + '</td>')
      + '<td class="no"><b>' + esc(chNo(b)) + '</b></td><td>' + chWhen(b.date_added) + '</td><td class="nw">' + chConn(b) + '</td><td>' + chWhat(b) + '</td><td class="nw">' + chStatus(b) + '</td>' + (compact ? '' : '<td class="r ch-acts">' + chActions(b) + '</td>') + '</tr>';
  }
  var chSel = {}, CHP = { offset: 0, limit: 10 }, CHS = 'open', chById = {};
  function chFilter() { return { state: CHS, conn: $('#ch-client').val() || '', period: $('#ch-period').val() || '', no: String($('#ch-no').val() || '').replace(/\D/g, '') }; }
  function chSelUpdate() {
    var n = Object.keys(chSel).length, $all = $('#ch-body .ch-ck'), on = $all.filter(':checked').length;
    $('#ch-selbar').toggleClass('show', n > 0); $('#ch-sel-n').text(CT.sel_n.replace('%d', n));
    $('#ch-ck-all').prop('checked', $all.length > 0 && on === $all.length).prop('indeterminate', on > 0 && on < $all.length).prop('disabled', !$all.length);
  }
  var chXhr = null;
  function loadChanges() {
    var f = chFilter();
    $('#ch-reset').toggle(!!(f.conn || f.period || f.no));
    if (chXhr) { chXhr.abort(); } // a slower earlier answer (another filter, the list before an undo) must never draw over this one
    chXhr = $.getJSON(chUrl + '&' + $.param($.extend({}, f, { offset: CHP.offset, limit: CHP.limit })), function (j) {
      var rows = j.rows || [], h = '', $cl = $('#ch-client'), keep = $cl.val(), c = j.counts || {};
      chById = {}; $.each(rows, function (i, b) { chById[b.batch_id] = b; h += chRow(b, false); });
      if (j.connections && $cl.children().length <= 1) { $.each(j.connections, function (i, x) { $cl.append($('<option>').val(x.conn).text(x.name + ' (' + x.batches + ')')); }); $cl.val(keep); }
      $('#ch-state [data-n]').each(function () { var n = c[$(this).data('n')]; $(this).text(n == null ? '' : n); });
      $('#ch-body').html(h); cardLabels($('#ch-body').closest('table'));
      $('#ch-empty-t').text(!c.total && !f.conn && !f.period && !f.no ? CT.none : (f.state === 'open' && !f.no ? CT.none_open : CT.none_match)); $('#ch-empty').toggle(!rows.length);
      $('#ch-pager').html(pagerHtml(+j.total || 0, CHP.offset, CHP.limit, [10, 25, 50]));
      $('#ch-rb-all').prop('disabled', f.state === 'done' || !(+c.open > 0)).find('span').text(CT.rb_all.replace('%d', f.state === 'done' ? 0 : +c.open || 0)); // never hidden: grey when there is nothing to undo
      chSelUpdate();
      if (!CHP.offset && f.state === 'open' && !f.conn && !f.period && !f.no) { // the overview shows the newest five that can still be rolled back
        var o = ''; $.each(rows.slice(0, 5), function (i, b) { o += chRow(b, true); });
        $('#ov-changes').html(rows.length ? '<div class="mcp-scroll"><table class="mcp-table mcp-cht"><tbody>' + o + '</tbody></table></div>' : '<div class="mcp-empty">' + esc(c.total ? CT.none_open : K.recent_none) + '</div>');
      }
   }).fail(function (x, st) { if (st === 'abort') { return; } $('#ch-body').empty(); $('#ch-pager').empty(); $('#ch-empty-t').text('{{ ui_load_failed|e('js') }}'); $('#ch-empty').show(); });
  }
  bindPager('#ch-pager', CHP, loadChanges);
  $('#ch-state').on('click', 'button', function () { CHS = $(this).data('state'); $('#ch-state button').removeClass('on'); $(this).addClass('on'); CHP.offset = 0; chSel = {}; loadChanges(); });
  $('#ch-client, #ch-period').on('change', function () { CHP.offset = 0; chSel = {}; loadChanges(); });
  $('#ch-reset').on('click', function () { $('#ch-client, #ch-period, #ch-no').val(''); CHP.offset = 0; chSel = {}; loadChanges(); });
  var chNoTimer = null;
  $('#ch-no').on('input', function () { clearTimeout(chNoTimer); chNoTimer = setTimeout(function () { CHP.offset = 0; chSel = {}; loadChanges(); }, 300); });
  $('#ch-body').on('change', '.ch-ck', function () { var id = $(this).closest('tr').data('batch'); if (this.checked) { chSel[id] = String($(this).closest('tr').data('order') || ''); } else { delete chSel[id]; } $(this).closest('tr').toggleClass('sel', this.checked); chSelUpdate(); });
  $('#ch-ck-all').on('change', function () { var on = this.checked; $('#ch-body .ch-ck').each(function () { if (this.checked !== on) { $(this).prop('checked', on).trigger('change'); } }); });
  $('#ch-sel-page').on('click', function () { var $a = $('#ch-ck-all'); $a.prop('checked', !$a.prop('checked') || $a.prop('indeterminate')).trigger('change'); }); // cards have no table header with the box
  $('#ch-sel-clear').on('click', function () { chSel = {}; $('#ch-body .ch-ck').prop('checked', false); $('#ch-body tr').removeClass('sel'); chSelUpdate(); });
  // roll back the chosen changes one by one, newest first; changes edited by hand afterwards are offered to force
  $('#ch-rb-sel').on('click', function () {
    var ids = Object.keys(chSel).sort(function (a, b) { return chSel[b] < chSel[a] ? -1 : (chSel[b] > chSel[a] ? 1 : 0); }); // newest first, like a rollback should go
    if (!ids.length || !confirm(CT.rb_q.replace('%d', ids.length))) { return; }
    var done = 0, conflicts = [], blocked = [], run = function (list, force, next) { // blocked: newer AI changes of the same fields, undone first, never forced
      if (!list.length) { next(); return; }
      var id = list.shift();
      $.post(chRbUrl, $.extend({ batch: id }, force ? { force: 1 } : {}), function (j) { done += +j.rolled_back || 0; if (!force && +j.conflicts > (+j.blocked || 0)) { conflicts.push(id); } (j.blocked_by || []).forEach(function (n) { if (blocked.indexOf(n) === -1) { blocked.push(n); } }); run(list, force, next); }, 'json').fail(function () { run(list, force, next); });
    };
    run(ids.slice(), false, function () {
      var finish = function () { chSel = {}; alert(CT.rb_done.replace('%d', done) + (blocked.length ? ' ' + CT.rb_blocked.replace('%s', '#' + blocked.join(', #')) : '')); loadChanges(); };
      if (conflicts.length && confirm(CT.confirm_force.replace('%d', conflicts.length))) { run(conflicts, true, finish); } else { finish(); }
    });
  });
  // everything the filter shows that can still be rolled back, newest first (one request: the server walks the batches)
  $('#ch-rb-all').on('click', function () {
    var f = chFilter(), n = +$('#ch-state [data-n="open"]').text() || 0;
    if (!n || !confirm(CT.rb_all_q.replace('%d', n))) { return; }
    var finish = function (msg) { alert(msg); chSel = {}; loadChanges(); };
    $.post(chRbAllUrl, { conn: f.conn, period: f.period }, function (j) {
      if (j.error) { alert(j.error); return; }
      if (+j.conflicts > (+j.blocked || 0) && confirm(CT.confirm_force.replace('%d', j.conflicts - (j.blocked || 0)))) { $.post(chRbAllUrl, { conn: f.conn, period: f.period, force: 1 }, function (j2) { finish(j2.success || j2.error); }, 'json'); } else { finish(j.success); }
    }, 'json');
  });
  var CHA = { insert: '{{ ui_act_insert|e('js') }}', update: '{{ ui_act_update|e('js') }}', 'delete': '{{ ui_act_delete|e('js') }}' };
  var CHST = { applied: CT.applied, executed: CT.applied, rolled_back: CT.rolled, conflict: CT.conflict, cancelled: CT.cancelled, pending: CT.pending };
  var chDet = null; // the change open in the dialog, for the download
  $(document).on('click', '.ch-det', function () {
    var batch = $(this).closest('tr').data('batch'), b = chById[batch];
    $.getJSON(chDetUrl + '&batch=' + batch, function (j) {
      chDet = { b: b, rows: j.rows || [] };
      $('#ch-dialog-title').text(b && +b.batch_no ? CT.det_no.replace('%d', +b.batch_no) : CT.details);
      var h = b ? '<div class="ch-dhd"><div><span class="k">' + esc(CT.no) + '</span><b>' + esc(chNo(b)) + '</b></div><div><span class="k">' + esc(CT.col_date) + '</span><b>' + esc(String(b.date_added).slice(0, 16)) + '</b></div><div><span class="k">' + esc(CT.col_conn) + '</span><b>' + esc(b.conn_name || b.client || '—') + '</b></div><div><span class="k">' + esc(CT.col_what) + '</span><b>' + esc(toolName(b.tool) || '—') + '</b></div><div><span class="k">' + esc(CT.col_status) + '</span>' + chStatus(b) + '</div></div>' : '';
      $.each(j.rows || [], function (i, c) {
        var bf = c.before_data ? JSON.parse(c.before_data) : null, af = c.after_data ? JSON.parse(c.after_data) : null, cols = Object.keys(bf || af || {});
        var pk = ''; try { pk = $.map(JSON.parse(c.pk), function (v, k) { return k + ' ' + v; }).join(', '); } catch (e) { pk = c.pk; }
        h += '<table class="mcp-ftable"><caption>' + esc(tblNames(c.table_name)) + ' · ' + esc(CHA[c.action] || c.action) + ' · ' + esc(CHST[c.status] || c.status) + ' · ' + esc(pk) + '</caption><thead><tr><th></th><th>' + esc(CT.before) + '</th><th>' + esc(CT.after) + '</th></tr></thead><tbody>';
        $.each(cols, function (k, col) {
          var bv = bf ? bf[col] : null, av = af ? af[col] : null;
          if (c.action === 'update' && String(bv) === String(av)) { return; }
          h += '<tr><td><code>' + esc(col) + '</code></td><td style="word-break:break-all">' + (bf ? esc(bv) : '—') + '</td><td style="word-break:break-all">' + (af ? esc(av) : '—') + '</td></tr>';
        });
        h += '</tbody></table>';
      });
      if (+j.total > (j.rows || []).length) { h = '<p class="mcp-tdesc">' + esc('{{ ui_ch_shown|e('js') }}'.replace('%d', (j.rows || []).length).replace('%d', +j.total)) + '</p>' + h; }
      $('#ch-dialog-body').html(h || '<p class="mcp-empty">{{ ui_ch_no_details|e('js') }}</p>');
      showDialog(document.getElementById('ch-dialog'));
    });
  });
  // the open change as a file: one line per field, before and after
  $('#ch-dl').on('click', function () {
    if (!chDet) { return; }
    var b = chDet.b || {}, lines = [];
    lines.push([CT.no, CT.col_date, CT.col_conn, CT.col_what, CT.csv_table, CT.csv_action, CT.csv_key, CT.csv_field, CT.before, CT.after]);
    var cell = function (v) { v = v == null ? '' : String(v); return /[";\n\r]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v; };
    $.each(chDet.rows, function (i, c) {
      var bf = c.before_data ? JSON.parse(c.before_data) : null, af = c.after_data ? JSON.parse(c.after_data) : null, pk = '';
      try { pk = $.map(JSON.parse(c.pk), function (v, k) { return k + ' ' + v; }).join(', '); } catch (e) { pk = c.pk; }
      $.each(Object.keys(bf || af || {}), function (k, col) {
        var bv = bf ? bf[col] : null, av = af ? af[col] : null;
        if (c.action === 'update' && String(bv) === String(av)) { return; }
        lines.push([chNo(b), String(b.date_added || '').slice(0, 16), b.conn_name || b.client || '', toolName(b.tool), tblNames(c.table_name), CHA[c.action] || c.action, pk, col, bf ? bv : '', af ? av : '']);
      });
    });
    var csv = '\ufeff' + lines.map(function (r) { return r.map(cell).join(';'); }).join('\r\n'), a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' })); a.download = 'change-' + (+b.batch_no || b.batch_id || 'export') + '.csv';
    document.body.appendChild(a); a.click(); setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
  });
  $(document).on('click', '.ch-rb', function () {
    var batch = $(this).closest('tr').data('batch');
    if (!confirm(CT.confirm_rb)) { return; }
    $.post(chRbUrl, { batch: batch }, function (j) {
      if (+j.conflicts > (+j.blocked || 0) && confirm(CT.confirm_force.replace('%d', j.conflicts - (j.blocked || 0)))) { // a hand edit may be overwritten; a newer AI change is undone first
        $.post(chRbUrl, { batch: batch, force: 1 }, function (j2) { alert(j2.success || j2.error); loadChanges(); }, 'json');
      } else { alert(j.success || j.error); loadChanges(); }
    }, 'json');
  });
  $(document).on('click', '.ch-cancel', function () {
    var batch = $(this).closest('tr').data('batch');
    $.post(chCancelUrl, { batch: batch }, function (j) { alert(j.success || j.error); loadChanges(); }, 'json');
  });

  // ================= call log: filters + paging
  var LST = { ok: '{{ ui_st_ok|e('js') }}', error: '{{ ui_st_error|e('js') }}', denied: '{{ ui_st_denied|e('js') }}' };
  var LT = { admin: '{{ ui_log_admin|e('js') }}', _rollback: '{{ ui_log_rollback|e('js') }}', _cancel_delete: '{{ ui_log_cancel_delete|e('js') }}', _auth: '{{ ui_log_auth|e('js') }}', _oauth_login: '{{ ui_log_oauth_login|e('js') }}', _oauth_login_failed: '{{ ui_log_oauth_login_failed|e('js') }}' };
  var logsUrl = '{{ logs_url|raw }}'.replace(/&amp;/g, '&'), LGP = { offset: 0, limit: 50 }, logTimer = null, logXhr = null, logStarted = false;
  function logParams() { return { range: $('#log-range').val(), date_from: $('#lf-date-from').val(), date_to: $('#lf-date-to').val(), tool: $('#lf-tool').val(), status: $('#lf-status').val(), client: $('#lf-client').val(), q: $('#lf-q').val() }; }
  function loadLogs(reset) {
    if (reset) { LGP.offset = 0; }
    if (logXhr) { logXhr.abort(); }
    var f = logParams();
    $('#lf-reset').toggle(Object.keys(f).some(function (k) { return !!f[k]; }));
    logXhr = $.getJSON(logsUrl + '&' + $.param($.extend(f, { offset: LGP.offset, limit: LGP.limit })), function (j) {
      var $sel = $('#lf-tool');
      if ($sel.find('option').length <= 1) { $.each(j.tools || [], function (i, t) { $sel.append($('<option>').val(t).text(t)); }); }
      var html = '', dash = '<span class="mcp-muted">—</span>';
      $.each(j.rows || [], function (i, r) {
        var pill = r.status === 'ok' ? 'g' : (r.status === 'denied' ? 'y' : 'r'), who = String(r.client || ''), ev = LT[r.tool];
        who = who.indexOf('admin:') === 0 ? LT.admin + ' · ' + who.slice(6) : (who.indexOf('oauth:') === 0 ? who.slice(6).replace('/', ' · ') : who); // the owner's own actions (rollbacks, cancelled deletions) stand in the same log
        who = r.conn_name ? '<b>' + esc(r.conn_name) + '</b>' + (who ? '<div class="mcp-tdesc">' + esc(who) + '</div>' : '') : (who ? esc(who) : ''); // the owner's name for the connection first
        html += '<tr><td class="text-nowrap">' + esc(r.date_added) + '<div class="mcp-tdesc">' + esc(r.ip) + ' · #' + esc(r.mcp_log_id) + '</div></td><td class="nw">' + (who || dash) + '</td><td>' + (ev ? '<b>' + esc(ev) + '</b><div class="mcp-tdesc"><span class="mcp-tname">' + esc(r.tool) + '</span></div>' : '<span class="mcp-tname">' + esc(r.tool) + '</span>') + '</td>'
          + '<td><div class="mcp-tdesc mcp-clamp" style="margin:0;word-break:break-all">' + esc(r.arguments) + '</div>' + (r.message ? '<div class="mcp-tdesc mcp-clamp"' + (r.status === 'ok' ? '' : ' style="color:#b91c1c"') + '>' + esc(r.message) + '</div>' : '') + '</td>'
          + '<td><span class="mcp-pill ' + pill + '">' + esc(LST[r.status] || r.status) + '</span></td><td class="r">' + esc(r.duration_ms) + '</td></tr>';
      });
      $('#mcp-log-body').html(html); cardLabels($('#mcp-log-body').closest('table'));
      $('#mcp-log-empty').text(Object.keys(f).some(function (k) { return !!f[k]; }) ? '{{ ui_log_no_match|e('js') }}' : '{{ text_no_log|e('js') }}').toggle(!(j.rows || []).length);
      $('#log-pager').html(pagerHtml(+j.total || 0, LGP.offset, LGP.limit));
    }).fail(function (x, st) { if (st !== 'abort') { $('#mcp-log-body').empty(); $('#mcp-log-empty').text('{{ ui_load_failed|e('js') }}').show(); } });
  }
  bindPager('#log-pager', LGP, function () { loadLogs(false); });
  $('#mcp-log-body').on('click', '.mcp-clamp', function () { $(this).toggleClass('open'); });
  $('#lf-date-from, #lf-date-to, #lf-tool, #lf-status, #log-range').on('change', function () { loadLogs(true); });
  // the period of the list is also what the CSV downloads
  $('#log-range').on('change', function () { var r = this.value; $('a[data-export]').each(function () { this.href = this.href.replace(/&range=[a-z0-9-]+/, '') + (r ? '&range=' + r : ''); }); });
  // a typed date: day up to 31, month up to 12, year up to 9999 (the browser alone lets a six-digit year in)
  $('#lf-date-from, #lf-date-to').on('input change', function () { var v = String(this.value || ''), m = v.match(/^(\d+)-(\d\d)-(\d\d)$/); if (m && m[1].length > 4) { this.value = ''; } });
  $('#lf-client, #lf-q').on('input', function () { clearTimeout(logTimer); logTimer = setTimeout(function () { loadLogs(true); }, 300); });
  $('#lf-reset').on('click', function () { $('#lf-filters input, #lf-filters select').val(''); $('a[data-export]').each(function () { this.href = this.href.replace(/&range=[a-z0-9-]+/, ''); }); loadLogs(true); });
  $(document).on('mcp:hist', function (e, which) { if (which === 'log' && !logStarted) { logStarted = true; loadLogs(true); } if (which === 'changes') { loadChanges(); } });
  $(document).on('mcp:tab', function (e, href) { if (href === '#tab-history' && $('#hist-log').hasClass('active') && !logStarted) { logStarted = true; loadLogs(true); } });
  // ================= filter lists of History: our own list under the field; the select stays underneath for the value and its change event
  function ddLabel(sel) { var o = sel.options[sel.selectedIndex]; $(sel).siblings('.mcp-dd-btn').text(o ? o.text : ''); }
  function ddMenu(sel) {
    return $.map(sel.options, function (o, i) { return '<li role="option" data-i="' + i + '"' + (i === sel.selectedIndex ? ' class="on" aria-selected="true"' : '') + '>' + esc(o.text) + '</li>'; }).join('');
  }
  $('#ch-client, #ch-period, #lf-tool, #lf-status, #log-range').each(function () {
    var sel = this, $w = $('<div class="mcp-dd"></div>');
    $(sel).addClass('mcp-dd-src').attr('tabindex', '-1').wrap($w);
    $(sel).after('<button type="button" class="form-control mcp-dd-btn" aria-haspopup="listbox"></button><ul class="mcp-dd-menu" role="listbox"></ul>');
    ddLabel(sel);
    $(sel).on('change', function () { ddLabel(sel); });
  });
  var valSet = $.valHooks.select.set; // the code sets these selects without a change event (reset, refilled lists): keep the shown text right
  $.valHooks.select.set = function (el, v) { var r = valSet.apply(this, arguments); if ($(el).hasClass('mcp-dd-src')) { setTimeout(function () { ddLabel(el); }, 0); } return r; };
  $(document).on('click', '.mcp-dd-btn', function (e) {
    var $d = $(this).closest('.mcp-dd'), sel = $d.find('select')[0], was = $d.hasClass('open');
    $('.mcp-dd.open').removeClass('open');
    if (!was) { $d.find('.mcp-dd-menu').html(ddMenu(sel)); $d.addClass('open'); }
    e.stopPropagation();
  });
  $(document).on('click', '.mcp-dd-menu li', function (e) {
    var $d = $(this).closest('.mcp-dd'), sel = $d.find('select')[0];
    sel.selectedIndex = +$(this).data('i'); $d.removeClass('open'); $(sel).trigger('change'); e.stopPropagation();
  });
  $(document).on('click', function () { $('.mcp-dd.open').removeClass('open'); });
  $(document).on('keydown', '.mcp-dd-btn', function (e) {
    var $d = $(this).closest('.mcp-dd'), sel = $d.find('select')[0], n = sel.options.length;
    if (e.key === 'Escape') { $d.removeClass('open'); return; }
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); sel.selectedIndex = Math.max(0, Math.min(n - 1, sel.selectedIndex + (e.key === 'ArrowDown' ? 1 : -1))); $(sel).trigger('change'); if ($d.hasClass('open')) { $d.find('.mcp-dd-menu').html(ddMenu(sel)); } }
  });

  // ================= archives: one row per archive made (by the owner or the hourly clean-up), each one a CSV download
  var ARC = {{ archives_json|raw }}, arcUrl = '{{ export_url|raw }}'.replace(/&amp;/g, '&');
  function renderArchives(list) {
    ARC = list || [];
    $('#hist-archives-btn').prop('hidden', !ARC.length);
    $('#arc-body').html(ARC.map(function (a) {
      return '<tr><td><b>#' + esc(a.archive_id) + '</b></td><td class="nw">' + esc(a.date_from) + ' – ' + esc(a.date_to) + '</td><td>' + esc(a.created_by === 'auto' ? '{{ ui_arc_auto|e('js') }}' : (a.created_by || '—')) + '</td><td class="nw">' + esc(String(a.date_added).slice(0, 16)) + '</td>'
        + '<td class="r"><a class="mcp-btn s" href="' + esc(arcUrl + '&format=csv&range=archive-' + (+a.archive_id)) + '"><i class="[[i:download]]"></i> ' + esc('{{ ui_arc_download|e('js') }}') + '</a></td></tr>';
    }).join(''));
    cardLabels($('#arc-body').closest('table'));
  }
  renderArchives(ARC);
  $('#mcp-archive').on('click', function () {
    var days = prompt('{{ text_archive_prompt|e('js') }}', '90');
    if (!days) { return; }
    $.ajax({ url: '{{ archive_url|raw }}'.replace(/&amp;/g, '&'), type: 'post', data: { days: days }, dataType: 'json', success: function (j) { alert(j.success || j.error); if (j.archives) { renderArchives(j.archives); } loadLogs(true); } });
  });
  $('#mcp-clear-log').on('click', function () {
    if (!confirm('{{ ui_log_clear_confirm|e('js') }}')) { return; }
    $.ajax({ url: '{{ clear_log_url|raw }}'.replace(/&amp;/g, '&'), type: 'post', dataType: 'json', success: function (j) { alert(j.success || j.error); if (j.archives) { renderArchives(j.archives); } loadLogs(true); } });
  });

  // ================= connections
  // the owner's name for a connection: the pencil opens an input in place; Enter saves, Escape leaves it; an empty name gives the app's own name back
  $('.cn-rename').on('click', function () { // one app + user may hold several tokens (several rows): the input lives in the clicked row, the name goes to all of them
    var conn = $(this).data('conn'), $row = $(this).closest('.mcp-conn'), $b = $row.find('.cn-name'), was = $b.text(), app = $b.data('app');
    if ($b.find('input').length) { return; }
    var cur = was === app ? '' : was, $in = $('<input type="text" class="form-control cn-input" maxlength="64" placeholder="{{ ui_conn_rename_ph|e('js') }}">').val(cur), done = false;
    var finish = function (save) {
      if (done) { return; } done = true;
      var name = $.trim($in.val());
      if (!save || name === cur) { $b.text(was); return; }
      $.ajax({ url: '{{ rename_url|raw }}'.replace(/&amp;/g, '&'), type: 'post', data: { conn: conn, name: name }, dataType: 'json',
        success: function (j) {
          if (j.error) { alert(j.error); $b.text(was); return; }
          var $all = $('.cn-name[data-conn="' + conn + '"]');
          $all.text(j.name || app); $all.siblings('.cn-sub').find('.cn-app').prop('hidden', !j.name);
        },
        error: function () { $b.text(was); } });
    };
    $in.on('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); finish(true); } if (e.key === 'Escape') { finish(false); } }).on('blur', function () { finish(true); });
    $b.empty().append($in); $in.focus().select();
  });
  $(document).on('click', '[data-scroll]', function (e) { var t = $($(this).data('scroll')); if (t.length) { e.preventDefault(); $('html,body').animate({ scrollTop: t.offset().top - 70 }, 200); } });
  $('#mcp-show-more').on('click', function () { $('.mcp-conns').addClass('show-more'); $(this).remove(); });
  $('#mcp-toggle-inactive').on('click', function () { var $t = $('.mcp-conns').toggleClass('show-inactive'); $(this).text($t.hasClass('show-inactive') ? $(this).data('hide') : $(this).data('show')); });

  var revokeUrl = '{{ revoke_url|raw }}'.replace(/&amp;/g, '&');
  $('.mcp-revoke').on('click', function () {
    if (!confirm($(this).data('all') ? '{{ text_revoke_all|e('js') }}?' : '{{ text_revoke|e('js') }}?')) { return; }
    $.ajax({ url: revokeUrl + ($(this).data('all') ? '&all=1' : '&token_id=' + $(this).data('token-id')), type: 'post', dataType: 'json', success: function (j) { if (j.error) { alert(j.error); return; } isDirty = false; location.hash = '#tab-connections'; location.reload(); } });
  });

  // ================= prompts tab
  var PR = {{ prompts_json|raw }}, CTS = {{ content_json|raw }};
  var PRT = { open: '{{ ui_prompt_edit|e('js') }}', copy: '{{ ui_prompt_copy_tip|e('js') }}', copied: '{{ ui_copied|e('js') }}', save: '{{ ui_save|e('js') }}', cancel: '{{ ui_cancel|e('js') }}',
    reset: '{{ ui_prompt_reset|e('js') }}', del: '{{ ui_prompt_delete|e('js') }}', changed: '{{ ui_prompt_changed|e('js') }}', own: '{{ ui_prompt_own|e('js') }}',
    title: '{{ ui_prompt_field_title|e('js') }}', section: '{{ ui_prompt_field_section|e('js') }}', desc: '{{ ui_prompt_field_desc|e('js') }}', body: '{{ ui_prompt_field_body|e('js') }}',
    reset_q: '{{ ui_prompt_reset_q|e('js') }}', del_q: '{{ ui_prompt_delete_q|e('js') }}', none: '{{ text_no_matches|e('js') }}', on: '{{ ui_prompt_on|e('js') }}',
    sec_name: '{{ ui_section_name|e('js') }}', sec_del: '{{ ui_section_delete|e('js') }}', sec_empty: '{{ ui_section_empty|e('js') }}', title_hint: '{{ ui_prompt_title_hint|e('js') }}', desc_hint: '{{ ui_prompt_desc_hint|e('js') }}', body_hint: '{{ ui_prompt_body_hint|e('js') }}' };
  var prUrl = '{{ prompts_url|raw }}'.replace(/&amp;/g, '&'), prOpen = null, secAdding = false;
  function prCopy(text, btn) {
    var done = function () { var $b = $(btn).attr('data-tip', PRT.copied).addClass('tip-on'); setTimeout(function () { $b.removeClass('tip-on'); }, 1400); };
    if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(text).then(done); return; }
    var $t = $('<textarea>').val(text).css({ position: 'fixed', top: '-1000px' }).appendTo('body'); $t[0].select(); document.execCommand('copy'); $t.remove(); done();
  }
  function prEditor(p) {
    var isNew = !p;
    p = p || { name: '', title: '', description: '', body: '', enabled: true, custom: true, section: Object.keys(PR.sections)[0] || '' };
    var opts = Object.keys(PR.sections).map(function (k) { return '<option value="' + esc(k) + '"' + (k === p.section ? ' selected' : '') + '>' + esc(PR.sections[k]) + '</option>'; }).join('');
    var h = '<div class="pr-edit" data-name="' + esc(p.name) + '">';
    h += '<div class="row2"><div><label>' + esc(PRT.title) + '<span>' + esc(PRT.title_hint) + '</span></label><input type="text" class="form-control pr-f-title" maxlength="255" value="' + esc(p.title) + '"/></div>';
    h += '<div><label>' + esc(PRT.section) + '</label><select class="form-control pr-f-section">' + opts + '</select></div></div>';
    h += '<label>' + esc(PRT.desc) + '<span>' + esc(PRT.desc_hint) + '</span></label><input type="text" class="form-control pr-f-desc" maxlength="500" value="' + esc(p.description) + '"/>';
    h += '<label>' + esc(PRT.body) + '<span>' + esc(PRT.body_hint) + '</span></label><textarea class="form-control pr-f-body" rows="18" spellcheck="false">' + esc(p.body) + '</textarea>';
    h += '<div class="pr-ef">';
    if (!isNew) { h += '<button type="button" class="mcp-btn pr-del"><i class="[[i:trash]]"></i> ' + esc(PRT.del) + '</button>'; }
    if (!isNew && !p.custom && p.changed) { h += '<button type="button" class="mcp-btn pr-reset"><i class="[[i:undo]]"></i> ' + esc(PRT.reset) + '</button>'; }
    h += '<span class="pr-err"></span><span class="sp"></span><button type="button" class="mcp-btn pr-cancel">' + esc(PRT.cancel) + '</button><button type="button" class="mcp-btn p pr-save"><i class="[[i:save]]"></i> ' + esc(PRT.save) + '</button></div>';
    return h + '</div>';
  }
  function prRender() {
    var q = ($('#pr-search').val() || '').toLowerCase().trim(), h = '', any = false;
    if (secAdding) { h += '<div class="pr-secadd"><input type="text" class="form-control" id="pr-sec-title" maxlength="100" placeholder="' + esc(PRT.sec_name) + '"/><span class="pr-err"></span><button type="button" class="mcp-btn" id="pr-sec-cancel">' + esc(PRT.cancel) + '</button><button type="button" class="mcp-btn p" id="pr-sec-save">' + esc(PRT.save) + '</button></div>'; }
    if (prOpen === '') { h += prEditor(null); }
    $.each(PR.sections, function (key, title) {
      var all = PR.prompts.filter(function (p) { return p.section === key; });
      var list = all.filter(function (p) { return !q || (p.title + ' ' + p.description + ' ' + p.body).toLowerCase().indexOf(q) !== -1; });
      if (q && !list.length) { return; }
      any = true;
      h += '<div class="pr-gh"><span>' + esc(title) + '</span>' + (all.length ? '' : '<button type="button" class="pr-secdel" data-section="' + esc(key) + '">' + esc(PRT.sec_del) + '</button>') + '</div>';
      if (!all.length) { h += '<div class="pr-empty">' + esc(PRT.sec_empty) + '</div>'; }
      list.forEach(function (p) {
        h += '<div class="pr-row' + (p.enabled ? '' : ' off') + '" data-name="' + esc(p.name) + '">';
        h += '<label class="mcp-switch"><input type="checkbox" class="pr-on" aria-label="' + esc(PRT.on + ': ' + (p.title || p.name)) + '"' + (p.enabled ? ' checked' : '') + '/><i></i></label>';
        h += '<button type="button" class="pr-open" aria-expanded="' + (prOpen === p.name) + '"><span class="pr-t">' + esc(p.title) + (p.custom ? '<span class="pr-badge own">' + esc(PRT.own) + '</span>' : (p.changed ? '<span class="pr-badge">' + esc(PRT.changed) + '</span>' : '')) + '</span><span class="pr-d">' + esc(p.description) + '</span></button>';
        h += '<div class="pr-acts"><button type="button" class="mcp-btn pr-copy" data-tip="' + esc(PRT.copy) + '" aria-label="' + esc(PRT.copy) + '"><i class="[[i:copy]]"></i></button><button type="button" class="mcp-btn pr-edit-btn"><i class="[[i:pen]]"></i> ' + esc(PRT.open) + '</button></div></div>';
        if (prOpen === p.name) { h += prEditor(p); }
      });
    });
    $('#pr-list').html(any || prOpen === '' || secAdding ? h : '<div class="mcp-empty">' + esc(PRT.none) + '</div>');
  }
  function prFind(name) { return PR.prompts.filter(function (p) { return p.name === name; })[0]; }
  function prPost(data, $err, then) {
    $.ajax({ url: prUrl, type: 'post', data: data, dataType: 'json', success: function (j) {
      if (j.error) { if ($err && $err.length) { $err.text(j.error); } else { alert(j.error); } return; }
      PR.prompts = j.prompts; PR.sections = j.sections; if (then) { then(j); } prRender();
    } });
  }
  $('#pr-search').on('input', prRender);
  $('#pr-add').on('click', function () { prOpen = ''; secAdding = false; prRender(); $('#pr-list .pr-f-title').trigger('focus'); });
  $('#pr-add-section').on('click', function () { secAdding = true; prRender(); $('#pr-sec-title').trigger('focus'); });
  $('#pr-list').on('click', '#pr-sec-cancel', function () { secAdding = false; prRender(); });
  $('#pr-list').on('keydown', '#pr-sec-title', function (e) { if (e.key === 'Enter') { e.preventDefault(); $('#pr-sec-save').trigger('click'); } if (e.key === 'Escape') { $('#pr-sec-cancel').trigger('click'); } });
  $('#pr-list').on('click', '#pr-sec-save', function () { prPost({ op: 'section_add', title: $('#pr-sec-title').val() }, $(this).siblings('.pr-err'), function () { secAdding = false; }); });
  $('#pr-list').on('click', '.pr-secdel', function () { prPost({ op: 'section_delete', section: $(this).data('section') }); });
  $('#pr-list').on('click', '.pr-open, .pr-edit-btn', function () { var n = $(this).closest('.pr-row').data('name'); prOpen = prOpen === n ? null : n; prRender(); });
  $('#pr-list').on('click', '.pr-cancel', function () { prOpen = null; prRender(); });
  $('#pr-list').on('click', '.pr-copy', function () { var p = prFind($(this).closest('[data-name]').data('name')); prCopy(p.body, this); });
  $('#pr-list').on('change', '.pr-on', function () { var p = prFind($(this).closest('.pr-row').data('name')); prPost({ op: 'save', name: p.name, title: p.title, description: p.description, body: p.body, section: p.section, enabled: this.checked ? 1 : 0 }); });
  $('#pr-list').on('click', '.pr-save', function () {
    var $e = $(this).closest('.pr-edit'), name = $e.data('name'), p = prFind(name);
    prPost({ op: name ? 'save' : 'create', name: name, title: $e.find('.pr-f-title').val(), section: $e.find('.pr-f-section').val(), description: $e.find('.pr-f-desc').val(), body: $e.find('.pr-f-body').val(), enabled: p ? (p.enabled ? 1 : 0) : 1 }, $e.find('.pr-err'), function () { prOpen = null; });
  });
  $('#pr-list').on('click', '.pr-reset', function () { var p = prFind($(this).closest('.pr-edit').data('name')); if (!confirm(PRT.reset_q)) { return; } prPost({ op: 'reset', name: p.name }, null, function () { prOpen = null; }); });
  $('#pr-list').on('click', '.pr-del', function () { var p = prFind($(this).closest('.pr-edit').data('name')); if (!confirm(PRT.del_q)) { return; } prPost({ op: 'delete', name: p.name }, null, function () { prOpen = null; }); });

  // ================= settings: AI behaviour, styling, images (saved with the settings form)
  function ctSync() {
    var c = $.extend({}, CTS);
    $('[data-ct]').each(function () { c[$(this).data('ct')] = this.checked; });
    if (!c.html) { c.css = false; }
    $('#ct-css').prop('disabled', !c.html).prop('checked', c.css); $('#ct-css-row').toggleClass('dis', !c.html);
    $('.ct-risk').each(function () { $(this).toggleClass('risky', !c[$(this).data('risk')]); });
    // languages the AI works with: the unticked ones are saved (a language added to the store later is open); never all
    var $langs = $('[data-lang]');
    if ($langs.length) { c.lang_off = $langs.filter(':not(:checked)').map(function () { return String($(this).data('lang')); }).get(); }
    $('#ct-json').val(JSON.stringify(c));
    CTS = c;
  }

  $('[data-ct]').each(function () { this.checked = !!CTS[$(this).data('ct')]; });
  $('[data-lang]').each(function () { this.checked = $.inArray(String($(this).data('lang')), CTS.lang_off || []) < 0; });
  if (!$('[data-lang]:checked').length) { $('[data-lang]').prop('checked', true); } // every language unticked was saved by hand: the server treats it as all open
  $('#ct-behaviour, #tab-general').on('change input', '[data-ct]', ctSync);
  $('#tab-general').on('change', '[data-lang]', function () {
    if (!$('[data-lang]:checked').length) { this.checked = true; } // at least one language stays: the AI needs one to write in
    ctSync();
  });
  ctSync();
  prRender();

  // ================= start
  // every control in a settings row is named by the row's title (screen readers; the switch itself has no text)
  $('.mcp-ui .set-row').each(function () { var t = $.trim($(this).find('.set-t b').first().text()); $(this).find('.set-c input:not([type=hidden]), .set-c select, .set-c textarea').each(function () { if (t && !this.getAttribute('aria-label')) { this.setAttribute('aria-label', t); } }); });
  $('.mcp-ui button, .mcp-ui a.mcp-btn').each(function () { if (!$.trim($(this).text()) && !this.getAttribute('aria-label') && this.title) { this.setAttribute('aria-label', this.title); } });
  $('.mcp-ui input:not([type=hidden]), .mcp-ui textarea').each(function () { if (!this.getAttribute('aria-label') && !(this.id && document.querySelector('label[for="' + this.id + '"]')) && !$(this).closest('label').length && this.placeholder) { this.setAttribute('aria-label', this.placeholder); } });
  // phones show tables as cards: every cell carries its column name
  function cardLabels(table) { var hs = $(table).find('thead th').map(function () { return $.trim($(this).text()); }).get(); $(table).find('tbody tr').each(function () { $(this).children('td').each(function (i) { if (hs[i]) { this.setAttribute('data-label', hs[i]); } }); }); }
  window.mcpCardLabels = cardLabels;
  $('.mcp-table.mcp-cards').each(function () { cardLabels(this); });
  rerender(); renderKpis(); loadChanges();
  var start = location.hash && (HIST_ALIASES[location.hash] || $(location.hash + '.mcp-pane').length) ? location.hash : null;
  if (!start) { try { start = localStorage.getItem('mcp-tab'); } catch (err) {} }
  if (start && start !== '#tab-overview') { openTab(start); }
})();
