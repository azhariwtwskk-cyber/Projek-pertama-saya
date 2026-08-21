
(() => {
  const normalise = value => String(value ?? '').trim().toLowerCase();

  class CpmsSmartTable {
    constructor(table) {
      this.table = table;
      this.body = table.tBodies[0];
      this.rows = Array.from(this.body?.rows || []);
      this.headers = Array.from(table.tHead?.rows[0]?.cells || []);
      this.page = 1;
      this.pageSize = Number(table.dataset.pageSize || 10);
      this.query = '';
      this.sortIndex = -1;
      this.sortDirection = 'asc';
      this.hidden = new Set();
      this.title = table.dataset.exportTitle || document.title;
      this.mount();
      this.apply();
    }

    mount() {
      const wrap = document.createElement('div');
      wrap.className = 'smart-table-shell';
      this.table.parentNode.insertBefore(wrap, this.table);
      wrap.appendChild(this.table.parentNode.removeChild(this.table));
      this.shell = wrap;

      this.rows.forEach(row => {
        Array.from(row.cells).forEach((cell, index) => {
          cell.dataset.label = this.headers[index]?.textContent.trim() || '';
        });
      });

      const toolbar = document.createElement('div');
      toolbar.className = 'smart-table-toolbar';
      toolbar.innerHTML = `
        <div class="smart-table-toolbar-left">
          <div class="smart-table-search">
            <input type="search" placeholder="Search current records..." aria-label="Search table">
          </div>
          <select class="smart-table-select" aria-label="Rows per page">
            <option value="10">10 rows</option>
            <option value="25">25 rows</option>
            <option value="50">50 rows</option>
            <option value="100">100 rows</option>
          </select>
        </div>
        <div class="smart-table-toolbar-right">
          <button type="button" class="smart-table-button" data-action="csv">CSV</button>
          <button type="button" class="smart-table-button" data-action="excel">Excel</button>
          <button type="button" class="smart-table-button" data-action="print">Print / PDF</button>
          <div class="smart-table-menu-wrap">
            <button type="button" class="smart-table-button" data-action="columns">Columns</button>
            <div class="smart-table-menu"></div>
          </div>
        </div>`;
      wrap.insertBefore(toolbar, this.table);

      const summary = document.createElement('div');
      summary.className = 'smart-table-summary';
      summary.innerHTML = `<span class="smart-table-count"></span><div class="smart-table-pagination"></div>`;
      wrap.appendChild(summary);

      this.search = toolbar.querySelector('input');
      this.sizeSelect = toolbar.querySelector('select');
      this.sizeSelect.value = String(this.pageSize);
      this.count = summary.querySelector('.smart-table-count');
      this.pagination = summary.querySelector('.smart-table-pagination');
      this.menu = toolbar.querySelector('.smart-table-menu');

      this.headers.forEach((header, index) => {
        const label = header.textContent.trim() || `Column ${index + 1}`;
        if (label) {
          const option = document.createElement('label');
          option.innerHTML = `<input type="checkbox" checked data-column="${index}"><span>${this.escape(label)}</span>`;
          this.menu.appendChild(option);
        }
        if (header.dataset.noSort === undefined && label !== '') {
          header.classList.add('smart-sortable');
          header.tabIndex = 0;
          const sort = () => this.sort(index, header);
          header.addEventListener('click', sort);
          header.addEventListener('keydown', e => {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); sort(); }
          });
        }
      });

      this.search.addEventListener('input', () => {
        this.query = normalise(this.search.value);
        this.page = 1;
        this.apply();
      });
      this.sizeSelect.addEventListener('change', () => {
        this.pageSize = Number(this.sizeSelect.value);
        this.page = 1;
        this.apply();
      });
      toolbar.addEventListener('click', e => {
        const action = e.target.closest('[data-action]')?.dataset.action;
        if (!action) return;
        if (action === 'columns') this.menu.classList.toggle('is-open');
        if (action === 'csv') this.exportDelimited(',');
        if (action === 'excel') this.exportExcel();
        if (action === 'print') window.print();
      });
      this.menu.addEventListener('change', e => {
        const index = Number(e.target.dataset.column);
        e.target.checked ? this.hidden.delete(index) : this.hidden.add(index);
        this.applyColumns();
      });
      document.addEventListener('click', e => {
        if (!toolbar.querySelector('.smart-table-menu-wrap').contains(e.target)) {
          this.menu.classList.remove('is-open');
        }
      });
    }

    escape(value) {
      const div = document.createElement('div');
      div.textContent = value;
      return div.innerHTML;
    }

    filteredRows() {
      return this.rows.filter(row => {
        if (!this.query) return true;
        return normalise(row.innerText).includes(this.query);
      });
    }

    sort(index, header) {
      const direction = this.sortIndex === index && this.sortDirection === 'asc' ? 'desc' : 'asc';
      this.sortIndex = index;
      this.sortDirection = direction;
      this.headers.forEach(h => h.removeAttribute('data-sort-direction'));
      header.dataset.sortDirection = direction;
      const factor = direction === 'asc' ? 1 : -1;
      this.rows.sort((a, b) => {
        const av = a.cells[index]?.dataset.sortValue || a.cells[index]?.innerText || '';
        const bv = b.cells[index]?.dataset.sortValue || b.cells[index]?.innerText || '';
        const an = Number(String(av).replace(/[^0-9.-]/g, ''));
        const bn = Number(String(bv).replace(/[^0-9.-]/g, ''));
        if (String(av).match(/^\s*[-+]?\d[\d,.]*\s*$/) && String(bv).match(/^\s*[-+]?\d[\d,.]*\s*$/)) {
          return (an - bn) * factor;
        }
        return String(av).localeCompare(String(bv), undefined, {numeric:true, sensitivity:'base'}) * factor;
      });
      this.rows.forEach(row => this.body.appendChild(row));
      this.page = 1;
      this.apply();
    }

    applyColumns() {
      [this.headers, ...this.rows.map(row => Array.from(row.cells))].forEach(cells => {
        cells.forEach((cell, index) => cell.classList.toggle('smart-table-hidden-column', this.hidden.has(index)));
      });
    }

    apply() {
      const filtered = this.filteredRows();
      const pages = Math.max(1, Math.ceil(filtered.length / this.pageSize));
      this.page = Math.min(this.page, pages);
      const start = (this.page - 1) * this.pageSize;
      const visible = new Set(filtered.slice(start, start + this.pageSize));
      this.rows.forEach(row => row.hidden = !visible.has(row));
      this.count.textContent = filtered.length
        ? `Showing ${start + 1}–${Math.min(start + this.pageSize, filtered.length)} of ${filtered.length} records`
        : 'No matching records';
      this.renderPagination(pages);
      this.applyColumns();
    }

    renderPagination(pages) {
      this.pagination.innerHTML = '';
      const add = (label, page, disabled = false, active = false) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = `smart-page-button${active ? ' is-active' : ''}`;
        button.textContent = label;
        button.disabled = disabled;
        button.addEventListener('click', () => { this.page = page; this.apply(); });
        this.pagination.appendChild(button);
      };
      add('‹', Math.max(1, this.page - 1), this.page === 1);
      const from = Math.max(1, this.page - 2);
      const to = Math.min(pages, from + 4);
      for (let p = from; p <= to; p++) add(String(p), p, false, p === this.page);
      add('›', Math.min(pages, this.page + 1), this.page === pages);
    }

    exportRows() {
      return this.filteredRows();
    }

    exportDelimited(delimiter) {
      const headers = this.headers.filter((_, i) => !this.hidden.has(i)).map(h => h.innerText.trim());
      const rows = this.exportRows().map(row =>
        Array.from(row.cells).filter((_, i) => !this.hidden.has(i)).map(cell => cell.innerText.trim())
      );
      const quote = value => `"${String(value).replaceAll('"', '""')}"`;
      const content = [headers, ...rows].map(row => row.map(quote).join(delimiter)).join('\r\n');
      this.download(new Blob(['\ufeff' + content], {type:'text/csv;charset=utf-8'}), this.fileName('csv'));
    }

    exportExcel() {
      const headers = this.headers.filter((_, i) => !this.hidden.has(i)).map(h => h.innerText.trim());
      const rows = this.exportRows().map(row =>
        Array.from(row.cells).filter((_, i) => !this.hidden.has(i)).map(cell => cell.innerText.trim())
      );
      const esc = v => String(v).replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;');
      const html = `<html><head><meta charset="utf-8"></head><body><table border="1"><tr>${headers.map(h=>`<th>${esc(h)}</th>`).join('')}</tr>${rows.map(r=>`<tr>${r.map(v=>`<td>${esc(v)}</td>`).join('')}</tr>`).join('')}</table></body></html>`;
      this.download(new Blob([html], {type:'application/vnd.ms-excel'}), this.fileName('xls'));
    }

    fileName(ext) {
      return `${this.title.replace(/[^a-z0-9]+/gi,'_').replace(/^_|_$/g,'') || 'CPMS_Export'}_${new Date().toISOString().slice(0,10)}.${ext}`;
    }

    download(blob, name) {
      const url = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = name;
      document.body.appendChild(link);
      link.click();
      link.remove();
      setTimeout(() => URL.revokeObjectURL(url), 500);
    }
  }

  document.querySelectorAll('table[data-smart-table]').forEach(table => new CpmsSmartTable(table));
})();
