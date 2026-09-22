const SPREADSHEET_ID = '1j9ucRgbGcB56olVTGiBJDJNMxgggB1jg4KUWZuslUwA';
const SHARED_SECRET = 'PASTE_SECRET_FROM_ANKH_ADMIN';
const ORDERS_SHEET = 'Orders';
const ITEMS_SHEET = 'Order Items';

function doPost(e) {
  const lock = LockService.getScriptLock();
  try {
    lock.waitLock(10000);
    const data = JSON.parse((e && e.postData && e.postData.contents) || '{}');

    if (!SHARED_SECRET || SHARED_SECRET === 'PASTE_SECRET_FROM_ANKH_ADMIN' || data.secret !== SHARED_SECRET) {
      return json_({ ok: false, error: 'Unauthorised' });
    }

    if (data.event === 'ping') {
      return json_({ ok: true, message: 'ANKH Sheets webhook is ready' });
    }

    const ss = SpreadsheetApp.openById(SPREADSHEET_ID);

    if (data.event === 'order_delete') {
      const reference = String(data.reference || '').trim();
      if (!reference) return json_({ ok: false, error: 'Order reference is missing' });
      deleteOrder_(ss, reference);
      SpreadsheetApp.flush();
      return json_({ ok: true, deleted: reference });
    }

    if (data.event !== 'order_upsert' || !data.order) {
      return json_({ ok: false, error: 'Unsupported event' });
    }

    upsertOrder_(ss, data.order);
    SpreadsheetApp.flush();

    return json_({ ok: true, order: String(data.order.reference || '') });
  } catch (err) {
    return json_({ ok: false, error: String(err && err.message ? err.message : err) });
  } finally {
    try { lock.releaseLock(); } catch (_) {}
  }
}

function upsertOrder_(ss, order) {
  const orders = ss.getSheetByName(ORDERS_SHEET);
  const items = ss.getSheetByName(ITEMS_SHEET);
  if (!orders || !items) throw new Error('Orders sheets are missing');

  const reference = String(order.reference || '').trim();
  if (!reference) throw new Error('Order reference is missing');

  const row = [
    reference,
    dateOrText_(order.created),
    String(order.customer || ''),
    String(order.phone || ''),
    String(order.referrer || ''),
    String(order.address || ''),
    String(order.status || 'New'),
    Number(order.total_pence || 0) / 100,
    String(order.notes || ''),
    new Date()
  ];

  const existingRow = findOrderRow_(orders, reference);
  if (existingRow) {
    orders.getRange(existingRow, 1, 1, row.length).setValues([row]);
  } else {
    orders.appendRow(row);
  }

  removeExistingItems_(items, reference);

  const itemRows = (order.items || []).map(item => [
    reference,
    String(item.name || ''),
    Number(item.quantity || 0),
    Number(item.unit_price_pence || 0) / 100,
    (Number(item.unit_price_pence || 0) * Number(item.quantity || 0)) / 100,
    String(order.status || 'New')
  ]).filter(row => row[2] > 0);

  if (itemRows.length) {
    items.getRange(items.getLastRow() + 1, 1, itemRows.length, 6).setValues(itemRows);
  }
}

function deleteOrder_(ss, reference) {
  const orders = ss.getSheetByName(ORDERS_SHEET);
  const items = ss.getSheetByName(ITEMS_SHEET);
  if (!orders || !items) throw new Error('Orders sheets are missing');

  const row = findOrderRow_(orders, reference);
  if (row) orders.deleteRow(row);
  removeExistingItems_(items, reference);
}

function findOrderRow_(sheet, reference) {
  const lastRow = sheet.getLastRow();
  if (lastRow < 2) return 0;
  const refs = sheet.getRange(2, 1, lastRow - 1, 1).getDisplayValues().flat();
  const index = refs.findIndex(value => String(value).trim() === reference);
  return index < 0 ? 0 : index + 2;
}

function removeExistingItems_(sheet, reference) {
  for (let row = sheet.getLastRow(); row >= 2; row--) {
    if (String(sheet.getRange(row, 1).getDisplayValue()).trim() === reference) {
      sheet.deleteRow(row);
    }
  }
}

function dateOrText_(value) {
  const date = new Date(value || '');
  return isNaN(date.getTime()) ? String(value || '') : date;
}

function json_(payload) {
  return ContentService
    .createTextOutput(JSON.stringify(payload))
    .setMimeType(ContentService.MimeType.JSON);
}
