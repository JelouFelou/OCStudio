console.log("Character Creator Script Loaded!");

// -------------------------------------------------------
// Ładowanie pól szablonu
// -------------------------------------------------------
async function loadTemplateFields(templateId) {
    if (!templateId) return;

    try {
        const response = await fetch(`/getTemplateData?id=${templateId}`);
        const template = await response.json();

        if (template.error) { console.error(template.error); return; }

        const leftContainer  = document.getElementById('left-fields-container');
        const rightContainer = document.getElementById('right-fields-container');

        leftContainer.innerHTML  = '';
        rightContainer.innerHTML = '';

        if (!template.fields || template.fields.length === 0) {
            leftContainer.innerHTML = '<p style="color:var(--text-muted);">Ten szablon postaci nie zawiera żadnych pól.</p>';
            return;
        }

        template.fields.forEach(field => {
            const wrapper = buildFieldWidget(field);
            if (!wrapper) return;
            if (field.location === 'right') rightContainer.appendChild(wrapper);
            else                            leftContainer.appendChild(wrapper);
        });

    } catch (err) {
        console.error('Błąd pobierania danych szablonu postaci:', err);
    }
}

// -------------------------------------------------------
// Budowanie widżetu pola dla kreatora postaci
// -------------------------------------------------------
function buildFieldWidget(field) {
    const fieldId  = field.id;
    const ftype    = field.field_type;
    const savedRaw = (typeof window.characterFieldValues !== 'undefined' && window.characterFieldValues[fieldId] != null)
        ? window.characterFieldValues[fieldId] : null;

    const wrapper = document.createElement('div');
    wrapper.className = 'input-group';

    const label = document.createElement('label');
    label.innerText = field.label;
    wrapper.appendChild(label);

    switch(ftype) {

        // ---- TEKST ----
        case 'text': {
            const inp = document.createElement('input');
            inp.type = 'text';
            inp.placeholder = 'Wpisz wartość...';
            inp.name = `field_values[${fieldId}]`;
            if (savedRaw) inp.value = savedRaw;
            wrapper.appendChild(inp);
            break;
        }

        // ---- DŁUGI TEKST ----
        case 'textarea': {
            const ta = document.createElement('textarea');
            ta.placeholder = 'Wpisz tekst...';
            ta.name = `field_values[${fieldId}]`;
            ta.rows = 5;
            ta.style.resize = 'vertical';
            if (savedRaw) ta.value = savedRaw;
            wrapper.appendChild(ta);
            break;
        }

        // ---- LISTA (bullet points) ----
        case 'list': {
            // Kontener wizualny
            const listWrap = document.createElement('div');
            listWrap.style.cssText = 'border:1px solid var(--border,#ddd);border-radius:8px;padding:8px 10px;background:var(--input-bg,#fff);';

            const bulletContainer = document.createElement('div');
            bulletContainer.className = 'bullet-list-container';

            // Ukryty input z JSON-em wierszy
            const hiddenInput = document.createElement('input');
            hiddenInput.type = 'hidden';
            hiddenInput.name = `field_values[${fieldId}]`;
            hiddenInput.id   = `list-hidden-${fieldId}`;

            let lines = [];
            try { lines = savedRaw ? JSON.parse(savedRaw) : []; } catch(e) { lines = savedRaw ? savedRaw.split('\n').filter(Boolean) : []; }
            if (lines.length === 0) lines = [''];

            const syncListHidden = () => {
                const vals = [...bulletContainer.querySelectorAll('.bullet-input')]
                    .map(i => i.value).filter(v => v.trim() !== '');
                hiddenInput.value = JSON.stringify(vals);
            };

            const addBulletRow = (val = '') => {
                const row = document.createElement('div');
                row.style.cssText = 'display:flex;align-items:center;gap:6px;margin-bottom:4px;';

                const dot = document.createElement('span');
                dot.textContent = '•';
                dot.style.cssText = 'font-size:1.2rem;color:var(--primary,#3498db);line-height:1;flex-shrink:0;';

                const inp = document.createElement('input');
                inp.type = 'text';
                inp.className = 'bullet-input';
                inp.value = val;
                inp.placeholder = 'Wpisz element...';
                inp.style.cssText = 'flex:1;border:none;outline:none;background:transparent;font-size:0.95rem;color:var(--text,#333);padding:2px 0;';

                // Enter = nowa linia, Backspace na pustej = usuń
                inp.addEventListener('keydown', e => {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        const next = addBulletRow('');
                        next.querySelector('.bullet-input')?.focus();
                        syncListHidden();
                    } else if (e.key === 'Backspace' && inp.value === '') {
                        e.preventDefault();
                        const prev = row.previousElementSibling;
                        row.remove();
                        prev?.querySelector('.bullet-input')?.focus();
                        syncListHidden();
                    }
                });
                inp.addEventListener('input', syncListHidden);

                row.appendChild(dot);
                row.appendChild(inp);
                bulletContainer.appendChild(row);
                syncListHidden();
                return row;
            };

            lines.forEach(l => addBulletRow(l));

            listWrap.appendChild(bulletContainer);
            wrapper.appendChild(listWrap);
            wrapper.appendChild(hiddenInput);
            syncListHidden();
            break;
        }

        // ---- ZDJĘCIE ----
        case 'image': {
            const imgWrap = document.createElement('div');
            imgWrap.style.cssText = 'display:flex;flex-direction:column;gap:8px;';

            let savedData = null;
            try { savedData = savedRaw ? JSON.parse(savedRaw) : null; } catch(e){}

            const preview = document.createElement('img');
            preview.style.cssText = 'max-width:100%;max-height:200px;border-radius:8px;object-fit:cover;border:1px solid var(--border,#ddd);display:none;';
            if (savedData?.url) {
                preview.src = savedData.url;
                preview.style.display = 'block';
            }

            const fileInput = document.createElement('input');
            fileInput.type   = 'file';
            fileInput.accept = 'image/*';
            fileInput.style.cssText = 'display:none;';
            fileInput.id = `file-${fieldId}`;

            const uploadBtn = document.createElement('label');
            uploadBtn.htmlFor = fileInput.id;
            uploadBtn.style.cssText = 'display:inline-flex;align-items:center;gap:6px;cursor:pointer;padding:7px 14px;border-radius:8px;border:1px dashed var(--primary,#3498db);color:var(--primary,#3498db);font-size:0.9rem;width:fit-content;';
            uploadBtn.innerHTML = '<i class="fa-solid fa-upload"></i> Wybierz zdjęcie';

            const hiddenInput = document.createElement('input');
            hiddenInput.type = 'hidden';
            hiddenInput.name = `field_values[${fieldId}]`;
            hiddenInput.id   = `img-hidden-${fieldId}`;
            if (savedRaw) hiddenInput.value = savedRaw;

            fileInput.addEventListener('change', async () => {
                const file = fileInput.files[0];
                if (!file) return;
                const uploaded = await uploadFile(file);
                if (uploaded) {
                    preview.src = uploaded.url;
                    preview.style.display = 'block';
                    hiddenInput.value = JSON.stringify({ url: uploaded.url, filename: uploaded.filename });
                }
            });

            imgWrap.appendChild(preview);
            imgWrap.appendChild(uploadBtn);
            imgWrap.appendChild(fileInput);
            wrapper.appendChild(imgWrap);
            wrapper.appendChild(hiddenInput);
            break;
        }

        // ---- GALERIA ----
        case 'image-gallery': {
            const gallWrap = document.createElement('div');
            gallWrap.style.cssText = 'display:flex;flex-direction:column;gap:10px;';

            const thumbsContainer = document.createElement('div');
            thumbsContainer.style.cssText = 'display:flex;flex-wrap:wrap;gap:8px;';

            const hiddenInput = document.createElement('input');
            hiddenInput.type = 'hidden';
            hiddenInput.name = `field_values[${fieldId}]`;
            hiddenInput.id   = `gallery-hidden-${fieldId}`;

            let images = [];
            try { images = savedRaw ? JSON.parse(savedRaw) : []; } catch(e){}

            const syncGallery = () => { hiddenInput.value = JSON.stringify(images); };

            const addThumb = (img) => {
                const thumb = document.createElement('div');
                thumb.style.cssText = 'position:relative;width:80px;height:80px;';

                const imgEl = document.createElement('img');
                imgEl.src = img.url;
                imgEl.style.cssText = 'width:80px;height:80px;object-fit:cover;border-radius:6px;border:1px solid var(--border,#ddd);';

                const removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.innerHTML = '<i class="fa-solid fa-xmark"></i>';
                removeBtn.style.cssText = 'position:absolute;top:2px;right:2px;background:rgba(0,0,0,0.6);color:#fff;border:none;border-radius:50%;width:20px;height:20px;font-size:0.7rem;cursor:pointer;display:flex;align-items:center;justify-content:center;';
                removeBtn.addEventListener('click', () => {
                    images = images.filter(i => i.filename !== img.filename);
                    thumb.remove();
                    syncGallery();
                });

                thumb.appendChild(imgEl);
                thumb.appendChild(removeBtn);
                thumbsContainer.appendChild(thumb);
            };

            images.forEach(img => addThumb(img));
            syncGallery();

            const fileInput = document.createElement('input');
            fileInput.type     = 'file';
            fileInput.accept   = 'image/*';
            fileInput.multiple = true;
            fileInput.style.cssText = 'display:none;';
            fileInput.id = `gallery-file-${fieldId}`;

            const uploadBtn = document.createElement('label');
            uploadBtn.htmlFor = fileInput.id;
            uploadBtn.style.cssText = 'display:inline-flex;align-items:center;gap:6px;cursor:pointer;padding:7px 14px;border-radius:8px;border:1px dashed var(--primary,#3498db);color:var(--primary,#3498db);font-size:0.9rem;width:fit-content;';
            uploadBtn.innerHTML = '<i class="fa-solid fa-upload"></i> Dodaj zdjęcia';

            fileInput.addEventListener('change', async () => {
                for (const file of fileInput.files) {
                    const uploaded = await uploadFile(file);
                    if (uploaded) {
                        const img = { url: uploaded.url, filename: uploaded.filename };
                        images.push(img);
                        addThumb(img);
                        syncGallery();
                    }
                }
                fileInput.value = '';
            });

            gallWrap.appendChild(thumbsContainer);
            gallWrap.appendChild(uploadBtn);
            gallWrap.appendChild(fileInput);
            wrapper.appendChild(gallWrap);
            wrapper.appendChild(hiddenInput);
            break;
        }

        // ---- TABELA ----
        case 'table': {
            let cfg = {};
            try { cfg = JSON.parse(field.placeholder || '{}'); } catch(e){}
            const rowNames = Array.isArray(cfg.rows) ? cfg.rows : (Array.isArray(cfg) ? cfg : []);

            let savedRows = {};
            try { savedRows = savedRaw ? JSON.parse(savedRaw) : {}; } catch(e){}

            if (rowNames.length === 0) {
                const empty = document.createElement('p');
                empty.style.cssText = 'color:var(--text-muted,#999);font-size:0.85rem;';
                empty.innerText = 'Ta tabela nie ma zdefiniowanych wierszy.';
                wrapper.appendChild(empty);
                break;
            }

            const tableWrap = document.createElement('div');
            tableWrap.style.cssText = 'border:1px solid var(--border,#ddd);border-radius:8px;overflow:hidden;';

            const hiddenInput = document.createElement('input');
            hiddenInput.type  = 'hidden';
            hiddenInput.name  = `field_values[${fieldId}]`;
            hiddenInput.id    = `table-hidden-${fieldId}`;
            hiddenInput.value = JSON.stringify(savedRows);

            rowNames.forEach((rowName, idx) => {
                const row = document.createElement('div');
                row.style.cssText = `display:flex;align-items:stretch;${idx < rowNames.length-1 ? 'border-bottom:1px solid var(--border,#ddd);' : ''}`;

                const nameCell = document.createElement('div');
                nameCell.style.cssText = 'flex:0 0 38%;padding:8px 12px;background:var(--surface-alt,#f5f5f5);font-size:0.88rem;font-weight:600;color:var(--text,#333);display:flex;align-items:center;border-right:1px solid var(--border,#ddd);word-break:break-word;';
                nameCell.innerText = rowName;

                const valueCell = document.createElement('div');
                valueCell.style.cssText = 'flex:1;display:flex;align-items:stretch;';

                const inp = document.createElement('input');
                inp.type = 'text';
                inp.placeholder = 'Wpisz wartość...';
                inp.dataset.rowKey = rowName;
                inp.style.cssText = 'width:100%;border:none;outline:none;padding:8px 12px;background:transparent;font-size:0.9rem;color:var(--text,#333);';
                if (savedRows[rowName] !== undefined) inp.value = savedRows[rowName];
                inp.addEventListener('input', () => updateTableHiddenInput(fieldId, tableWrap));

                valueCell.appendChild(inp);
                row.appendChild(nameCell);
                row.appendChild(valueCell);
                tableWrap.appendChild(row);
            });

            wrapper.appendChild(tableWrap);
            wrapper.appendChild(hiddenInput);
            break;
        }

        // ---- DATA ----
        case 'date': {
            let cfg = {};
            try { cfg = JSON.parse(field.placeholder || '{}'); } catch(e){}
            const months    = Array.isArray(cfg.months) ? cfg.months : [];
            const eras      = Array.isArray(cfg.eras)   ? cfg.eras   : [];
            const defYear   = cfg.defaultYear || '';

            let saved = {};
            try { saved = savedRaw ? JSON.parse(savedRaw) : {}; } catch(e){}

            const hiddenInput = document.createElement('input');
            hiddenInput.type  = 'hidden';
            hiddenInput.name  = `field_values[${fieldId}]`;
            hiddenInput.id    = `date-hidden-${fieldId}`;

            const dateRow = document.createElement('div');
            dateRow.style.cssText = 'display:flex;gap:8px;flex-wrap:wrap;align-items:center;';

            // Miesiąc
            const monthSel = document.createElement('select');
            monthSel.style.cssText = 'flex:2;min-width:120px;';
            if (months.length === 0) {
                const opt = document.createElement('option');
                opt.value = ''; opt.textContent = '— Brak miesięcy —';
                monthSel.appendChild(opt);
            } else {
                months.forEach((m, i) => {
                    const opt = document.createElement('option');
                    opt.value = i;
                    opt.textContent = m.name;
                    if (saved.monthIndex !== undefined && saved.monthIndex === i) opt.selected = true;
                    monthSel.appendChild(opt);
                });
            }

            // Dzień
            const daySel = document.createElement('select');
            daySel.style.cssText = 'flex:1;min-width:70px;';

            const populateDays = () => {
                const mIdx = parseInt(monthSel.value);
                const maxDays = (months[mIdx]?.days) || 31;
                const prevDay = parseInt(daySel.value) || (saved.day || 1);
                daySel.innerHTML = '';
                for (let d = 1; d <= maxDays; d++) {
                    const opt = document.createElement('option');
                    opt.value = d; opt.textContent = d;
                    if (d === prevDay) opt.selected = true;
                    daySel.appendChild(opt);
                }
            };
            populateDays();
            monthSel.addEventListener('change', () => { populateDays(); syncDate(); });

            // Rok
            const yearInp = document.createElement('input');
            yearInp.type  = 'text';
            yearInp.placeholder = 'Rok';
            yearInp.value = saved.year !== undefined ? saved.year : defYear;
            yearInp.style.cssText = 'flex:1;min-width:70px;';

            const syncDate = () => {
                const obj = {
                    day:        parseInt(daySel.value)  || 1,
                    monthIndex: parseInt(monthSel.value)|| 0,
                    monthName:  months[parseInt(monthSel.value)]?.name || '',
                    year:       yearInp.value.trim(),
                };
                if (eraSel) obj.era = eraSel.value;
                hiddenInput.value = JSON.stringify(obj);
            };

            daySel.addEventListener('change', syncDate);
            yearInp.addEventListener('input', syncDate);

            dateRow.appendChild(daySel);
            dateRow.appendChild(monthSel);
            dateRow.appendChild(yearInp);

            // Era (opcjonalna)
            let eraSel = null;
            if (eras.length > 0) {
                eraSel = document.createElement('select');
                eraSel.style.cssText = 'flex:1;min-width:80px;';
                eras.forEach(e => {
                    const opt = document.createElement('option');
                    opt.value = e; opt.textContent = e;
                    if (saved.era === e) opt.selected = true;
                    eraSel.appendChild(opt);
                });
                eraSel.addEventListener('change', syncDate);
                dateRow.appendChild(eraSel);
            }

            wrapper.appendChild(dateRow);
            wrapper.appendChild(hiddenInput);
            syncDate();
            break;
        }

        // ---- WYBÓR Z LISTY ----
        case 'select': {
            let cfg = {};
            try { cfg = JSON.parse(field.placeholder || '{}'); } catch(e){}
            const options = Array.isArray(cfg.options) ? cfg.options : [];

            const sel = document.createElement('select');
            sel.name = `field_values[${fieldId}]`;

            const defOpt = document.createElement('option');
            defOpt.value = ''; defOpt.textContent = '— Wybierz —';
            if (!savedRaw) defOpt.selected = true;
            sel.appendChild(defOpt);

            options.forEach(o => {
                const opt = document.createElement('option');
                opt.value = o; opt.textContent = o;
                if (savedRaw === o) opt.selected = true;
                sel.appendChild(opt);
            });

            wrapper.appendChild(sel);
            break;
        }

        default:
            return null;
    }

    return wrapper;
}

// -------------------------------------------------------
// Upload pliku do serwera
// -------------------------------------------------------
async function uploadFile(file) {
    const formData = new FormData();
    formData.append('file', file);

    try {
        const res = await fetch('/uploadFile', { method: 'POST', body: formData });
        if (!res.ok) throw new Error('Upload failed');
        return await res.json(); // { url, filename }
    } catch(e) {
        console.error('Upload error:', e);
        alert('Nie udało się przesłać pliku: ' + file.name);
        return null;
    }
}

// -------------------------------------------------------
// Helper tabeli
// -------------------------------------------------------
function updateTableHiddenInput(fieldId, tableWrap) {
    const hiddenInput = document.getElementById(`table-hidden-${fieldId}`);
    if (!hiddenInput) return;
    const result = {};
    tableWrap.querySelectorAll('input[data-row-key]').forEach(inp => {
        result[inp.dataset.rowKey] = inp.value;
    });
    hiddenInput.value = JSON.stringify(result);
}

// -------------------------------------------------------
// Aktualizacja template_id w ukrytym inpucie formularza
// -------------------------------------------------------
function updateTemplateId(value) {
    const hiddenInput = document.getElementById('form-template-id');
    if (hiddenInput) hiddenInput.value = value;
    loadTemplateFields(value);
}

// -------------------------------------------------------
// Renderowanie widgetów pól dla wariantów
// -------------------------------------------------------
function buildVariantFieldWidget(fieldWrapper) {
    // fieldWrapper to element [data-variant-field] LUB rodzic z [data-variant-field]
    let fieldContainer = fieldWrapper;
    if (!fieldWrapper.hasAttribute('data-variant-field')) {
        fieldContainer = fieldWrapper.querySelector('[data-variant-field]');
        if (!fieldContainer) return;
    }
    
    const fieldId = fieldContainer.dataset.fieldId;
    const fieldType = fieldContainer.dataset.fieldType;
    const fieldConfig = fieldContainer.dataset.fieldConfig;
    const fieldLabel = fieldContainer.dataset.fieldLabel;
    const renderer = fieldContainer.querySelector('.variant-field-renderer');
    const hiddenInput = fieldContainer.querySelector('.variant-field-input');
    
    if (!renderer || !hiddenInput) return;
    
    renderer.innerHTML = '';
    
    switch(fieldType) {
        case 'date': {
            let cfg = {};
            try { cfg = JSON.parse(fieldConfig || '{}'); } catch(e){}
            const months = Array.isArray(cfg.months) ? cfg.months : [];
            const eras = Array.isArray(cfg.eras) ? cfg.eras : [];
            const defYear = cfg.defaultYear || '';
            
            let saved = {};
            try { saved = JSON.parse(hiddenInput.value || '{}'); } catch(e){}
            
            const dateRow = document.createElement('div');
            dateRow.style.cssText = 'display:flex;gap:8px;flex-wrap:wrap;align-items:center;';
            
            // Dzień
            const daySel = document.createElement('select');
            daySel.style.cssText = 'flex:1;min-width:70px;';
            
            // Miesiąc
            const monthSel = document.createElement('select');
            monthSel.style.cssText = 'flex:2;min-width:120px;';
            if (months.length === 0) {
                const opt = document.createElement('option');
                opt.value = ''; opt.textContent = '— Brak miesięcy —';
                monthSel.appendChild(opt);
            } else {
                months.forEach((m, i) => {
                    const opt = document.createElement('option');
                    opt.value = i;
                    opt.textContent = m.name;
                    if (saved.monthIndex !== undefined && saved.monthIndex === i) opt.selected = true;
                    monthSel.appendChild(opt);
                });
            }
            
            const populateDays = () => {
                const mIdx = parseInt(monthSel.value);
                const maxDays = (months[mIdx]?.days) || 31;
                const prevDay = parseInt(daySel.value) || (saved.day || 1);
                daySel.innerHTML = '';
                for (let d = 1; d <= maxDays; d++) {
                    const opt = document.createElement('option');
                    opt.value = d; opt.textContent = d;
                    if (d === prevDay) opt.selected = true;
                    daySel.appendChild(opt);
                }
            };
            populateDays();
            monthSel.addEventListener('change', () => { populateDays(); syncDate(); });
            
            // Rok
            const yearInp = document.createElement('input');
            yearInp.type = 'text';
            yearInp.placeholder = 'Rok';
            yearInp.value = saved.year !== undefined ? saved.year : defYear;
            yearInp.style.cssText = 'flex:1;min-width:70px;';
            
            const syncDate = () => {
                const obj = {
                    day: parseInt(daySel.value) || 1,
                    monthIndex: parseInt(monthSel.value) || 0,
                    monthName: months[parseInt(monthSel.value)]?.name || '',
                    year: yearInp.value.trim(),
                };
                if (eraSel) obj.era = eraSel.value;
                hiddenInput.value = JSON.stringify(obj);
            };
            
            daySel.addEventListener('change', syncDate);
            yearInp.addEventListener('input', syncDate);
            
            dateRow.appendChild(daySel);
            dateRow.appendChild(monthSel);
            dateRow.appendChild(yearInp);
            
            // Era (opcjonalna)
            let eraSel = null;
            if (eras.length > 0) {
                eraSel = document.createElement('select');
                eraSel.style.cssText = 'flex:1;min-width:80px;';
                eras.forEach(e => {
                    const opt = document.createElement('option');
                    opt.value = e; opt.textContent = e;
                    if (saved.era === e) opt.selected = true;
                    eraSel.appendChild(opt);
                });
                eraSel.addEventListener('change', syncDate);
                dateRow.appendChild(eraSel);
            }
            
            renderer.appendChild(dateRow);
            syncDate();
            break;
        }
        
        case 'image-gallery': {
            let images = [];
            try { images = hiddenInput.value ? JSON.parse(hiddenInput.value) : []; } catch(e){}
            
            const gallWrap = document.createElement('div');
            gallWrap.style.cssText = 'display:flex;flex-direction:column;gap:10px;';
            
            const thumbsContainer = document.createElement('div');
            thumbsContainer.style.cssText = 'display:flex;flex-wrap:wrap;gap:8px;';
            
            const syncGallery = () => { hiddenInput.value = JSON.stringify(images); };
            
            const addThumb = (img) => {
                const thumb = document.createElement('div');
                thumb.style.cssText = 'position:relative;width:80px;height:80px;';
                
                const imgEl = document.createElement('img');
                imgEl.src = img.url;
                imgEl.style.cssText = 'width:80px;height:80px;object-fit:cover;border-radius:6px;border:1px solid var(--border,#ddd);';
                
                const removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.innerHTML = '<i class="fa-solid fa-xmark"></i>';
                removeBtn.style.cssText = 'position:absolute;top:2px;right:2px;background:rgba(0,0,0,0.6);color:#fff;border:none;border-radius:50%;width:20px;height:20px;font-size:0.7rem;cursor:pointer;display:flex;align-items:center;justify-content:center;';
                removeBtn.addEventListener('click', (e) => {
                    e.preventDefault();
                    images = images.filter(i => i.filename !== img.filename);
                    thumb.remove();
                    syncGallery();
                });
                
                thumb.appendChild(imgEl);
                thumb.appendChild(removeBtn);
                thumbsContainer.appendChild(thumb);
            };
            
            images.forEach(img => addThumb(img));
            syncGallery();
            
            const fileInput = document.createElement('input');
            fileInput.type = 'file';
            fileInput.accept = 'image/*';
            fileInput.multiple = true;
            fileInput.style.cssText = 'display:none;';
            fileInput.id = `gallery-input-variant-${fieldId}`;
            
            const uploadBtn = document.createElement('label');
            uploadBtn.htmlFor = fileInput.id;
            uploadBtn.style.cssText = 'display:inline-flex;align-items:center;gap:6px;cursor:pointer;padding:7px 14px;border-radius:8px;border:1px dashed var(--primary,#3498db);color:var(--primary,#3498db);font-size:0.9rem;width:fit-content;';
            uploadBtn.innerHTML = '<i class="fa-solid fa-upload"></i> Dodaj zdjęcia';
            
            fileInput.addEventListener('change', async () => {
                for (const file of fileInput.files) {
                    const uploaded = await uploadFile(file);
                    if (uploaded) {
                        const img = { url: uploaded.url, filename: uploaded.filename };
                        images.push(img);
                        addThumb(img);
                        syncGallery();
                    }
                }
                fileInput.value = '';
            });
            
            gallWrap.appendChild(thumbsContainer);
            gallWrap.appendChild(uploadBtn);
            gallWrap.appendChild(fileInput);
            renderer.appendChild(gallWrap);
            break;
        }
        
        case 'image': {
            let savedImg = null;
            try { savedImg = hiddenInput.value ? JSON.parse(hiddenInput.value) : null; } catch(e){}
            
            const imgWrap = document.createElement('div');
            imgWrap.style.cssText = 'display:flex;gap:10px;flex-direction:column;';
            
            const preview = document.createElement('img');
            preview.style.cssText = 'width:100%;max-width:200px;height:auto;border-radius:6px;border:1px solid var(--border,#ddd);';
            if (savedImg?.url) preview.src = savedImg.url;
            else preview.style.display = 'none';
            
            const fileInput = document.createElement('input');
            fileInput.type = 'file';
            fileInput.accept = 'image/*';
            fileInput.style.cssText = 'display:none;';
            fileInput.id = `image-input-variant-${fieldId}`;
            
            const uploadLabel = document.createElement('label');
            uploadLabel.htmlFor = fileInput.id;
            uploadLabel.style.cssText = 'display:inline-flex;align-items:center;gap:6px;cursor:pointer;padding:7px 14px;border-radius:8px;border:1px dashed var(--primary,#3498db);color:var(--primary,#3498db);font-size:0.9rem;width:fit-content;';
            uploadLabel.innerHTML = '<i class="fa-solid fa-upload"></i> Wgraj zdjęcie';
            
            fileInput.addEventListener('change', async () => {
                if (fileInput.files.length > 0) {
                    const uploaded = await uploadFile(fileInput.files[0]);
                    if (uploaded) {
                        savedImg = { url: uploaded.url, filename: uploaded.filename };
                        preview.src = uploaded.url;
                        preview.style.display = 'block';
                        hiddenInput.value = JSON.stringify(savedImg);
                    }
                }
                fileInput.value = '';
            });
            
            imgWrap.appendChild(preview);
            imgWrap.appendChild(uploadLabel);
            imgWrap.appendChild(fileInput);
            renderer.appendChild(imgWrap);
            break;
        }
        
        case 'select': {
            let cfg = {};
            try { cfg = JSON.parse(fieldConfig || '{}'); } catch(e){}
            const options = Array.isArray(cfg.options) ? cfg.options : [];
            
            const sel = document.createElement('select');
            
            const defOpt = document.createElement('option');
            defOpt.value = '';
            defOpt.textContent = '— Zostaw puste dla bazowej —';
            if (!hiddenInput.value) defOpt.selected = true;
            sel.appendChild(defOpt);
            
            options.forEach(o => {
                const opt = document.createElement('option');
                opt.value = o;
                opt.textContent = o;
                if (hiddenInput.value === o) opt.selected = true;
                sel.appendChild(opt);
            });
            
            sel.addEventListener('change', () => {
                hiddenInput.value = sel.value;
            });
            
            renderer.appendChild(sel);
            break;
        }
        
        case 'list': {
            let lines = [];
            try { lines = hiddenInput.value ? JSON.parse(hiddenInput.value) : []; } catch(e){}
            if (lines.length === 0) lines = [''];
            
            const listWrap = document.createElement('div');
            listWrap.style.cssText = 'border:1px solid var(--border,#ddd);border-radius:8px;padding:8px 10px;background:var(--input-bg,#fff);';
            
            const bulletContainer = document.createElement('div');
            bulletContainer.className = 'bullet-list-container-variant';
            
            const syncList = () => {
                const vals = [...bulletContainer.querySelectorAll('.bullet-input-variant')]
                    .map(i => i.value)
                    .filter(v => v.trim() !== '');
                hiddenInput.value = JSON.stringify(vals);
            };
            
            const addBulletInput = (value = '') => {
                const row = document.createElement('div');
                row.style.cssText = 'display:flex;gap:6px;margin-bottom:6px;align-items:center;';
                
                const inp = document.createElement('input');
                inp.type = 'text';
                inp.className = 'bullet-input-variant';
                inp.style.cssText = 'flex:1;border:1px solid var(--border,#ddd);border-radius:4px;padding:6px 8px;';
                inp.value = value;
                inp.placeholder = 'Element listy...';
                inp.addEventListener('input', syncList);
                inp.addEventListener('keypress', (e) => {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        addBulletInput('');
                    }
                });
                
                const removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.innerHTML = '<i class="fa-solid fa-xmark"></i>';
                removeBtn.style.cssText = 'background:none;border:none;color:var(--text-muted);cursor:pointer;padding:0;width:20px;height:20px;display:flex;align-items:center;justify-content:center;';
                removeBtn.addEventListener('click', (e) => {
                    e.preventDefault();
                    row.remove();
                    syncList();
                });
                
                row.appendChild(inp);
                row.appendChild(removeBtn);
                bulletContainer.appendChild(row);
            };
            
            lines.forEach(line => addBulletInput(line));
            if (lines.length === 0 || !lines[lines.length - 1]) {
                addBulletInput('');
            }
            
            listWrap.appendChild(bulletContainer);
            renderer.appendChild(listWrap);
            syncList();
            break;
        }
        
        case 'table': {
            let cfg = {};
            try { cfg = JSON.parse(fieldConfig || '{}'); } catch(e){}
            const rowNames = Array.isArray(cfg.rows) ? cfg.rows : (Array.isArray(cfg) ? cfg : []);
            
            let savedRows = {};
            try { savedRows = hiddenInput.value ? JSON.parse(hiddenInput.value) : {}; } catch(e){}
            
            if (rowNames.length === 0) {
                const empty = document.createElement('p');
                empty.style.cssText = 'color:var(--text-muted,#999);font-size:0.85rem;';
                empty.innerText = 'Ta tabela nie ma zdefiniowanych wierszy.';
                renderer.appendChild(empty);
                break;
            }
            
            const tableWrap = document.createElement('div');
            tableWrap.style.cssText = 'border:1px solid var(--border,#ddd);border-radius:8px;overflow:hidden;';
            
            rowNames.forEach((rowName, idx) => {
                const row = document.createElement('div');
                row.style.cssText = `display:flex;align-items:stretch;${idx < rowNames.length-1 ? 'border-bottom:1px solid var(--border,#ddd);' : ''}`;
                
                const nameCell = document.createElement('div');
                nameCell.style.cssText = 'flex:0 0 38%;padding:8px 12px;background:var(--surface-alt,#f5f5f5);font-size:0.88rem;font-weight:600;color:var(--text,#333);display:flex;align-items:center;border-right:1px solid var(--border,#ddd);word-break:break-word;';
                nameCell.innerText = rowName;
                
                const valueCell = document.createElement('div');
                valueCell.style.cssText = 'flex:1;display:flex;align-items:stretch;';
                
                const inp = document.createElement('input');
                inp.type = 'text';
                inp.placeholder = 'Wpisz wartość...';
                inp.dataset.rowKey = rowName;
                inp.style.cssText = 'width:100%;border:none;outline:none;padding:8px 12px;background:transparent;font-size:0.9rem;color:var(--text,#333);';
                if (savedRows[rowName] !== undefined) inp.value = savedRows[rowName];
                inp.addEventListener('input', () => {
                    const result = {};
                    tableWrap.querySelectorAll('input[data-row-key]').forEach(i => {
                        result[i.dataset.rowKey] = i.value;
                    });
                    hiddenInput.value = JSON.stringify(result);
                });
                
                valueCell.appendChild(inp);
                row.appendChild(nameCell);
                row.appendChild(valueCell);
                tableWrap.appendChild(row);
            });
            
            renderer.appendChild(tableWrap);
            break;
        }
        
        case 'textarea':
        case 'text':
        default: {
            const ta = document.createElement('textarea');
            ta.rows = 2;
            ta.style.cssText = 'width:100%;border:1px solid var(--border,#ddd);border-radius:6px;padding:8px;font-family:inherit;';
            ta.placeholder = 'Zostaw puste, aby użyć wartości bazowej';
            ta.value = hiddenInput.value;
            ta.addEventListener('input', () => {
                hiddenInput.value = ta.value;
            });
            renderer.appendChild(ta);
        }
    }
}

// -------------------------------------------------------
// Inicjalizacja wariantów po załadowaniu DOM
// -------------------------------------------------------
function initializeVariantFields() {
    document.querySelectorAll('[data-variant-field]').forEach(fieldWrapper => {
        buildVariantFieldWidget(fieldWrapper);
    });
}
