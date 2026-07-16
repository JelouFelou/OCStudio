const menuIcon = document.querySelector(".display-mobile.fa-bars");
const navList = document.querySelector("nav > div.container > ul");
let currentTargetLocation = 'left';

window.OCGridColumns = window.OCGridColumns || {
    apply(value) {
        const val = Math.max(1, Math.min(parseInt(value, 10) || 4, 12));
        const root = document.documentElement;
        root.style.setProperty('--grid-columns', val);
        root.classList.forEach(className => {
            if (/^grid-cols-\d+$/.test(className)) {
                root.classList.remove(className);
            }
        });
        root.classList.add(`grid-cols-${val}`);
    }
};

if (menuIcon && navList) {
    menuIcon.addEventListener("click", () => {
      if (navList.style.display === "block") {
        navList.style.display = "none";
      } else {
        navList.style.display = "block";
      }
    });
}

document.addEventListener('DOMContentLoaded', function() {
    const slider = document.getElementById('column-slider');
    const columnText = document.getElementById('column-count');
    const grid = document.querySelector('.dashboard-grid');

    if (slider && grid) {
        const minColumns = parseInt(slider.min, 10) || 1;
        const maxColumns = parseInt(slider.max, 10) || 12;
        const normalizeColumns = value => Math.max(minColumns, Math.min(parseInt(value, 10) || minColumns, maxColumns));

        slider.addEventListener('input', function() {
            const val = normalizeColumns(this.value);
            slider.value = val;
            document.body.classList.add('oc-grid-changing');
            window.clearTimeout(window.__ocGridChangingTimer);
            window.__ocGridChangingTimer = window.setTimeout(() => {
                document.body.classList.remove('oc-grid-changing');
            }, 180);
            
            // 1. Aktualizujemy tekst obok suwaka
            if (columnText) {
                columnText.innerText = val;
            }
            
            // 2. Aktualizujemy zmienną CSS bezpośrednio na elemencie lub dokumencie
            window.OCGridColumns?.apply(val);
            requestAnimationFrame(() => window.OCMediaFrame?.refresh(document));
            
            // Opcjonalnie: Zapisz preferencję użytkownika w LocalStorage, 
            // aby po odświeżeniu strony układ został zapamiętany
            localStorage.setItem('dashboard-columns', val);
        });

        // Wczytywanie zapisanego ustawienia po starcie strony
        const savedValue = localStorage.getItem('dashboard-columns');
        if (savedValue) {
            const val = normalizeColumns(savedValue);
            slider.value = val;
            if (columnText) {
                columnText.innerText = val;
            }
            window.OCGridColumns?.apply(val);
            requestAnimationFrame(() => window.OCMediaFrame?.refresh(document));
        }
    }
});

document.addEventListener('DOMContentLoaded', () => {
    const computed = getComputedStyle(document.documentElement).getPropertyValue('--grid-columns').trim();
    window.OCGridColumns.apply(computed || 4);
});

function addField(location) {
    const container = document.getElementById(location + '-fields');
    const template = document.getElementById('field-template');
    
    // Klonujemy szablon
    const clone = template.content.cloneNode(true);
    
    // Ustawiamy lokalizację (ukryte pole)
    clone.querySelector('.field-location').value = location;
    
    // Jeśli to prawa strona, możemy domyślnie ustawić typ "text"
    if (location === 'right') {
        // Możesz tu dodać specyficzną logikę dla infoboxu
    }

    container.appendChild(clone);
}

// Dodaj kilka pól na start
document.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('left-fields')) {
        addField('left');
        addField('right');
    }
});
