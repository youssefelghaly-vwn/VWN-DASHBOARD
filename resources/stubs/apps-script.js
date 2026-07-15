// resources/stubs/apps-script.js
/**
 * WORKER — Sheet Bridge
 * Paste into Extensions → Apps Script, then Deploy → Web app (Access: Anyone).
 * Returns every requested tab as JSON. No editing needed — sheet names come
 * from the ?sheets= query param sent by the dashboard.
 */
function doGet(e) {
  try {
    var ss = SpreadsheetApp.getActiveSpreadsheet();
    var requested = (e && e.parameter && e.parameter.sheets)
      ? e.parameter.sheets.split(',').map(function (s) { return s.trim(); })
      : ss.getSheets().map(function (s) { return s.getName(); });

    var payload = {};

    requested.forEach(function (name) {
      var sheet = ss.getSheetByName(name);
      if (!sheet) { return; }

      var values = sheet.getDataRange().getValues();
      if (values.length < 2) { payload[name] = []; return; }

      var headers = values.shift().map(function (h) { return String(h).trim(); });

      payload[name] = values
        .filter(function (row) {
          return row.some(function (c) { return String(c).trim() !== ''; });
        })
        .map(function (row) {
          var obj = {};
          headers.forEach(function (h, i) {
            if (h) { obj[h] = row[i] instanceof Date ? row[i].toISOString() : row[i]; }
          });
          return obj;
        });
    });

    return json(payload);
  } catch (err) {
    return json({ error: String(err) });
  }
}

function json(obj) {
  return ContentService
    .createTextOutput(JSON.stringify(obj))
    .setMimeType(ContentService.MimeType.JSON);
}