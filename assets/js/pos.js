/**
 * M39ODA - Artisan POS Controller
 * Human-crafted UI logic: Food boxes, Quick-Add, Atelier Customizer, WebAudio Feedback
 */

document.addEventListener('DOMContentLoaded', () => {
    // Application State
    let catalog = { sandwiches: [], ingredients: [] };
    let cart = []; // Line items
    let currentCustomizingSandwich = null;
    let currentActiveModifiers = []; // { id_ingredient, action, nom, prix_supplement }
    let selectedCategory = 'ALL';

    // DOM Elements
    const sandwichBoxesGrid = document.getElementById('sandwichBoxesGrid');
    const searchField = document.getElementById('searchField');
    const categoryNav = document.getElementById('categoryNav');
    const cartItemsScroll = document.getElementById('cartItemsScroll');
    const emptyCartView = document.getElementById('emptyCartView');
    const cartCountBadge = document.getElementById('cartCountBadge');
    const totalItemsCount = document.getElementById('totalItemsCount');
    const grandTotalDisplay = document.getElementById('grandTotalDisplay');
    const btnValidateOrder = document.getElementById('btnValidateOrder');
    const btnResetCart = document.getElementById('btnResetCart');
    const liveClockBadge = document.getElementById('liveClockBadge');

    // Customizer Modal Elements
    const modalCustomizer = document.getElementById('modalCustomizer');
    const modalItemTitle = document.getElementById('modalItemTitle');
    const modalItemThumb = document.getElementById('modalItemThumb');
    const modalItemBasePrice = document.getElementById('modalItemBasePrice');
    const modalQtyValue = document.getElementById('modalQtyValue');
    const btnMinusQty = document.getElementById('btnMinusQty');
    const btnPlusQty = document.getElementById('btnPlusQty');
    const gridRetraitsTiles = document.getElementById('gridRetraitsTiles');
    const gridSaucesTiles = document.getElementById('gridSaucesTiles');
    const gridSupplementsTiles = document.getElementById('gridSupplementsTiles');
    const modalSpecialNote = document.getElementById('modalSpecialNote');
    const modalLiveTotal = document.getElementById('modalLiveTotal');
    const btnConfirmAddToCart = document.getElementById('btnConfirmAddToCart');
    const btnCloseCustomizer = document.getElementById('btnCloseCustomizer');

    // Dual-Tickets Modal Elements
    const modalTickets = document.getElementById('modalTickets');
    const btnCloseTicketsModal = document.getElementById('btnCloseTicketsModal');
    const btnPrintDualTickets = document.getElementById('btnPrintDualTickets');
    const btnNewOrderFromTickets = document.getElementById('btnNewOrderFromTickets');
    const ticketClientBox = document.getElementById('ticketClientBox');
    const ticketKitchenBox = document.getElementById('ticketKitchenBox');

    // Subtle Synthesized Audio Chimes (Web Audio API - No external assets)
    const audioCtx = (typeof window.AudioContext !== 'undefined' || typeof window.webkitAudioContext !== 'undefined')
        ? new (window.AudioContext || window.webkitAudioContext)() : null;

    function playSound(type = 'add') {
        if (!audioCtx) return;
        if (audioCtx.state === 'suspended') {
            audioCtx.resume();
        }
        const now = audioCtx.currentTime;
        const osc = audioCtx.createOscillator();
        const gain = audioCtx.createGain();

        osc.connect(gain);
        gain.connect(audioCtx.destination);

        if (type === 'add') {
            osc.frequency.setValueAtTime(600, now);
            osc.frequency.exponentialRampToValueAtTime(1000, now + 0.08);
            gain.gain.setValueAtTime(0.08, now);
            gain.gain.exponentialRampToValueAtTime(0.001, now + 0.08);
            osc.start(now);
            osc.stop(now + 0.08);
        } else if (type === 'success') {
            [523.25, 659.25, 783.99].forEach((freq, i) => {
                const subOsc = audioCtx.createOscillator();
                const subGain = audioCtx.createGain();
                subOsc.connect(subGain);
                subGain.connect(audioCtx.destination);
                subOsc.frequency.setValueAtTime(freq, now + i * 0.06);
                subGain.gain.setValueAtTime(0.1, now + i * 0.06);
                subGain.gain.exponentialRampToValueAtTime(0.001, now + i * 0.06 + 0.25);
                subOsc.start(now + i * 0.06);
                subOsc.stop(now + i * 0.06 + 0.25);
            });
        }
    }

    // Live Clock Update
    function updateLiveClock() {
        if (!liveClockBadge) return;
        const now = new Date();
        liveClockBadge.textContent = now.toLocaleDateString('fr-FR', {
            weekday: 'short',
            day: '2-digit',
            month: 'short'
        }) + ' • ' + now.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    }
    setInterval(updateLiveClock, 1000);
    updateLiveClock();

    // 0. Skeleton loader: shows shimmer placeholders while fetching
    function showCatalogSkeleton() {
        sandwichBoxesGrid.innerHTML = Array(6).fill(0).map(() => `
            <div class="sandwich-box skeleton-box" aria-hidden="true">
                <div class="skeleton-img"></div>
                <div class="box-content" style="gap:10px;">
                    <div class="skeleton-line" style="width:70%; height:16px;"></div>
                    <div class="skeleton-line" style="width:90%; height:11px;"></div>
                    <div class="skeleton-line" style="width:55%; height:11px;"></div>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-top:8px;">
                        <div class="skeleton-line" style="height:32px; border-radius:8px;"></div>
                        <div class="skeleton-line" style="height:32px; border-radius:8px;"></div>
                    </div>
                </div>
            </div>
        `).join('');
    }

    // 1. Load Catalog from API
    async function fetchCatalog() {
        showCatalogSkeleton();
        try {
            const catalogUrl = (window.TENANT_RESTAURANT_ID) ? 'api/catalog.php?restaurant_id=' + window.TENANT_RESTAURANT_ID : 'api/catalog.php';
            const resp = await fetch(catalogUrl);
            const res = await resp.json();
            if (res.success) {
                catalog.sandwiches = res.data.sandwichs;
                catalog.ingredients = res.data.ingredients;
                if (res.restaurant && res.restaurant.nom) {
                    const brandTitle = document.querySelector('.brand-title');
                    if (brandTitle) brandTitle.textContent = res.restaurant.nom;
                    const brandSub = document.querySelector('.brand-subtitle');
                    if (brandSub && res.restaurant.ville) brandSub.textContent = 'Spécialités Traditionnelles • ' + res.restaurant.ville;
                }
                buildCategoryTabs();
                renderSandwichBoxes();
            } else {
                sandwichBoxesGrid.innerHTML = `<div style="grid-column:1/-1; text-align:center; color:var(--text-muted); padding:60px;"><div style="color:#dc2626;"><svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg></div><div style="font-weight:700; margin-top:8px;">${res.message || 'Impossible de charger le catalogue'}</div></div>`;
                console.error('Catalog API Error:', res.message);
            }
        } catch (err) {
            sandwichBoxesGrid.innerHTML = `<div style="grid-column:1/-1; text-align:center; color:var(--text-muted); padding:60px;"><div style="color:#d97706;"><svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 1l22 22"/><path d="M16.72 11.06A10.94 10.94 0 0 1 19 12.55"/><path d="M5 12.55a10.94 10.94 0 0 1 5.17-2.39"/><path d="M10.71 5.05A16 16 0 0 1 22.58 9"/><path d="M1.42 9a15.91 15.91 0 0 1 4.7-2.88"/><path d="M8.53 16.11a6 6 0 0 1 6.95 0"/><line x1="12" y1="20" x2="12.01" y2="20"/></svg></div><div style="font-weight:700; margin-top:8px;">Erreur de connexion au serveur</div></div>`;
            console.error('Fetch error:', err);
        }
    }

    // 2. Build Category Filter Chips
    function buildCategoryTabs() {
        const categories = ['ALL'];
        catalog.sandwiches.forEach(s => {
            if (s.categorie && !categories.includes(s.categorie)) {
                categories.push(s.categorie);
            }
        });

        categoryNav.innerHTML = '';
        const labels = {
            'ALL': 'Tout le Menu',
            'Ma3qouda': 'Sandwichs Maâkouda',
            'Combos': 'Menus Combos',
            'Duo': 'Menus Duo',
            'Boissons': 'Boissons Fraîches'
        };

        categories.forEach(cat => {
            const chip = document.createElement('button');
            chip.type = 'button';
            chip.className = `cat-chip ${selectedCategory === cat ? 'active' : ''}`;
            chip.textContent = labels[cat] || cat;
            chip.addEventListener('click', () => {
                selectedCategory = cat;
                document.querySelectorAll('.cat-chip').forEach(c => c.classList.remove('active'));
                chip.classList.add('active');
                renderSandwichBoxes();
            });
            categoryNav.appendChild(chip);
        });
    }

    // 3. Render Food Boxes Grid
    function renderSandwichBoxes() {
        sandwichBoxesGrid.innerHTML = '';

        const query = searchField ? searchField.value.toLowerCase().trim() : '';
        const filtered = catalog.sandwiches.filter(s => {
            const matchesCat = (selectedCategory === 'ALL' || s.categorie === selectedCategory);
            const matchesQuery = !query || s.nom.toLowerCase().includes(query) || (s.description && s.description.toLowerCase().includes(query));
            return matchesCat && matchesQuery;
        });

        if (filtered.length === 0) {
            sandwichBoxesGrid.innerHTML = `
                <div style="grid-column: 1/-1; text-align: center; color: var(--text-muted); padding: 50px;">
                    <div style="opacity:0.4; margin-bottom:8px;">
                        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="11" cy="11" r="8"/>
                            <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                        </svg>
                    </div>
                    <div style="font-weight:700;">Aucun sandwich trouvé pour cette recherche</div>
                </div>
            `;
            return;
        }

        filtered.forEach((s, index) => {
            const isAvailable = (parseInt(s.disponible ?? 1) === 1);
            const box = document.createElement('div');
            box.className = `sandwich-box ${isAvailable ? '' : 'is-out-of-stock'}`;
            box.setAttribute('data-id', s.id_sandwich);

            const defaultImg = 'https://images.unsplash.com/photo-1550547660-d9450f859349?w=500&auto=format&fit=crop&q=80';
            const imgUrl = s.image_url || defaultImg;
            
            let badgeHtml = '';
            if (!isAvailable) {
                badgeHtml = `<div class="box-badge rupture">RUPTURE DE STOCK</div>`;
            } else if (s.badge) {
                badgeHtml = `<div class="box-badge">${escapeHtml(s.badge)}</div>`;
            }

            const shortcutBadge = (index < 9 && isAvailable) 
                ? `<div class="box-shortcut-pill" title="Raccourci clavier N° ${index + 1}">${index + 1}</div>` 
                : '';

            const inCartCount = cart.filter(c => c.id_sandwich === parseInt(s.id_sandwich)).reduce((sum, c) => sum + c.quantite, 0);
            const inCartBadgeHtml = inCartCount > 0 ? `<div class="box-qty-in-cart" title="${inCartCount} dans la commande">${inCartCount}</div>` : '';
            if (inCartCount > 0) {
                box.classList.add('has-in-cart');
            }

            box.innerHTML = `
                <div class="box-hero-image-wrap">
                    <img src="${imgUrl}" alt="${escapeHtml(s.nom)}" class="box-hero-image" loading="lazy">
                    ${badgeHtml}
                    ${shortcutBadge}
                    ${inCartBadgeHtml}
                    <div class="box-price-pill">${parseFloat(s.prix).toFixed(2)} DH</div>
                </div>

                <div class="box-content">
                    <div class="box-header-title">${escapeHtml(s.nom)}</div>
                    <div class="box-desc">${escapeHtml(s.description || '')}</div>

                    <div class="box-actions-row">
                        <button type="button" class="btn-box-customize" title="Personnaliser les ingrédients et extras" ${isAvailable ? '' : 'disabled'}>
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M12 20h9"/>
                                <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/>
                            </svg>
                            <span>Personnaliser</span>
                        </button>
                    </div>
                </div>
            `;

            if (isAvailable) {
                // Customize Button: opens customization modal ONLY
                const btnCust = box.querySelector('.btn-box-customize');
                if (btnCust) {
                    btnCust.addEventListener('click', (e) => {
                        e.stopPropagation();
                        openAtelierModal(s);
                    });
                }

                // Tablet Touch: Tapping anywhere on the card directly adds it to the cart!
                box.addEventListener('click', () => {
                    addDirectToCart(s, box);
                    box.classList.add('box-added-flash');
                    setTimeout(() => box.classList.remove('box-added-flash'), 350);
                    updateBoxQtyBadges();
                });
            }

            sandwichBoxesGrid.appendChild(box);
        });
    }

    // Search filter event
    if (searchField) {
        searchField.addEventListener('input', () => {
            renderSandwichBoxes();
        });
    }

    // 4. Quick Direct Add (No customizations) + fly-to-cart animation
    function addDirectToCart(sandwich, sourceElement) {
        const item = {
            id_sandwich: parseInt(sandwich.id_sandwich),
            nom: sandwich.nom,
            quantite: 1,
            base_prix: parseFloat(sandwich.prix),
            prix_unitaire: parseFloat(sandwich.prix),
            preferences: [],
            commentaire: ''
        };

        // Check if an identical standard item is already in cart
        const existingIdx = cart.findIndex(c => 
            c.id_sandwich === item.id_sandwich && c.preferences.length === 0 && !c.commentaire
        );

        if (existingIdx !== -1) {
            cart[existingIdx].quantite += 1;
        } else {
            cart.push(item);
        }

        // Fly-to-cart animation
        if (sourceElement) {
            flyToCart(sourceElement);
        }

        playSound('add');
        renderCartSlip();
    }

    // Fly-to-cart animation: a dot flies from the clicked button to the cart badge
    function flyToCart(sourceEl) {
        const badge = cartCountBadge;
        if (!badge) return;
        const srcRect = sourceEl.getBoundingClientRect();
        const dstRect = badge.getBoundingClientRect();

        const dot = document.createElement('div');
        dot.style.cssText = `
            position: fixed;
            width: 14px; height: 14px;
            border-radius: 50%;
            background: var(--terracotta);
            box-shadow: 0 0 8px rgba(194,65,12,0.5);
            pointer-events: none;
            z-index: 9999;
            top: ${srcRect.top + srcRect.height / 2 - 7}px;
            left: ${srcRect.left + srcRect.width / 2 - 7}px;
            transition: top 0.5s cubic-bezier(0.25, 0.46, 0.45, 0.94),
                        left 0.5s cubic-bezier(0.25, 0.46, 0.45, 0.94),
                        opacity 0.5s ease,
                        transform 0.5s ease;
        `;
        document.body.appendChild(dot);

        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                dot.style.top  = `${dstRect.top  + dstRect.height / 2 - 7}px`;
                dot.style.left = `${dstRect.left + dstRect.width  / 2 - 7}px`;
                dot.style.opacity = '0';
                dot.style.transform = 'scale(0.3)';
            });
        });

        // Pulse the badge
        badge.classList.add('badge-ping');
        setTimeout(() => {
            badge.classList.remove('badge-ping');
            dot.remove();
        }, 600);
    }

    // 5. Open Atelier Customizer Modal
    function openAtelierModal(sandwich) {
        currentCustomizingSandwich = sandwich;
        currentActiveModifiers = [];

        modalItemTitle.textContent = sandwich.nom;
        modalItemThumb.src = sandwich.image_url || 'https://images.unsplash.com/photo-1550547660-d9450f859349?w=500&auto=format&fit=crop&q=80';
        modalItemBasePrice.textContent = 'Prix de base : ' + parseFloat(sandwich.prix).toFixed(2) + ' DH';
        modalQtyValue.textContent = '1';
        modalSpecialNote.value = '';

        // Section A: Retraits (Sans...)
        gridRetraitsTiles.innerHTML = '';
        const baseFree = catalog.ingredients.filter(ing => 
            parseFloat(ing.prix_supplement) === 0 && !ing.nom.toLowerCase().startsWith('sauce')
        );
        baseFree.forEach(ing => {
            const tile = document.createElement('div');
            tile.className = 'ingredient-tile';
            tile.innerHTML = `
                <div class="ing-tile-title">Sans ${escapeHtml(ing.nom)}</div>
                <div style="font-size:11px; color:#ef4444; font-weight:700;">Retirer</div>
            `;
            tile.addEventListener('click', () => {
                tile.classList.toggle('is-retire');
                toggleModifier(ing, 'RETIRER', tile.classList.contains('is-retire'));
            });
            gridRetraitsTiles.appendChild(tile);
        });

        // Section B: Sauces (Gratuit)
        gridSaucesTiles.innerHTML = '';
        const sauces = catalog.ingredients.filter(ing => 
            parseFloat(ing.prix_supplement) === 0 && ing.nom.toLowerCase().startsWith('sauce')
        );
        sauces.forEach(ing => {
            const tile = document.createElement('div');
            tile.className = 'ingredient-tile';
            tile.innerHTML = `
                <div class="ing-tile-title">+ ${escapeHtml(ing.nom)}</div>
                <div style="font-size:11px; color:#0284c7; font-weight:700;">Gratuit</div>
            `;
            tile.addEventListener('click', () => {
                tile.classList.toggle('is-ajoute-gratuit');
                toggleModifier(ing, 'AJOUTER', tile.classList.contains('is-ajoute-gratuit'));
            });
            gridSaucesTiles.appendChild(tile);
        });

        // Section C: Suppléments Payants
        gridSupplementsTiles.innerHTML = '';
        const extras = catalog.ingredients.filter(ing => parseFloat(ing.prix_supplement) > 0);
        extras.forEach(ing => {
            const tile = document.createElement('div');
            tile.className = 'ingredient-tile';
            tile.innerHTML = `
                <div class="ing-tile-title">+ ${escapeHtml(ing.nom)}</div>
                <div class="ing-tile-price">+${parseFloat(ing.prix_supplement).toFixed(2)} DH</div>
            `;
            tile.addEventListener('click', () => {
                tile.classList.toggle('is-ajoute-payant');
                toggleModifier(ing, 'AJOUTER', tile.classList.contains('is-ajoute-payant'));
            });
            gridSupplementsTiles.appendChild(tile);
        });

        updateModalPriceCalculation();
        modalCustomizer.classList.add('active');
    }

    function toggleModifier(ing, action, isActive) {
        if (isActive) {
            currentActiveModifiers.push({
                id_ingredient: parseInt(ing.id_ingredient),
                nom: ing.nom,
                action: action,
                prix_supplement: parseFloat(ing.prix_supplement)
            });
        } else {
            currentActiveModifiers = currentActiveModifiers.filter(m => 
                !(m.id_ingredient === parseInt(ing.id_ingredient) && m.action === action)
            );
        }
        updateModalPriceCalculation();
    }

    function updateModalPriceCalculation() {
        const qty = parseInt(modalQtyValue.textContent) || 1;
        const base = parseFloat(currentCustomizingSandwich.prix) || 0;
        let suppSum = 0;
        currentActiveModifiers.forEach(m => {
            if (m.action === 'AJOUTER') suppSum += m.prix_supplement;
        });
        const total = (base + suppSum) * qty;
        modalLiveTotal.textContent = total.toFixed(2) + ' DH';
    }

    btnMinusQty.addEventListener('click', () => {
        let q = parseInt(modalQtyValue.textContent) || 1;
        if (q > 1) {
            modalQtyValue.textContent = (q - 1).toString();
            updateModalPriceCalculation();
        }
    });

    btnPlusQty.addEventListener('click', () => {
        let q = parseInt(modalQtyValue.textContent) || 1;
        modalQtyValue.textContent = (q + 1).toString();
        updateModalPriceCalculation();
    });

    btnCloseCustomizer.addEventListener('click', () => {
        modalCustomizer.classList.remove('active');
    });

    // 6. Confirm Atelier Add
    btnConfirmAddToCart.addEventListener('click', () => {
        if (!currentCustomizingSandwich) return;

        const qty = parseInt(modalQtyValue.textContent) || 1;
        const basePrix = parseFloat(currentCustomizingSandwich.prix);
        let suppPrix = 0;
        currentActiveModifiers.forEach(m => {
            if (m.action === 'AJOUTER') suppPrix += m.prix_supplement;
        });
        const unitPrix = basePrix + suppPrix;

        cart.push({
            id_sandwich: parseInt(currentCustomizingSandwich.id_sandwich),
            nom: currentCustomizingSandwich.nom,
            quantite: qty,
            base_prix: basePrix,
            prix_unitaire: unitPrix,
            preferences: [...currentActiveModifiers],
            commentaire: modalSpecialNote.value.trim()
        });

        playSound('add');
        renderCartSlip();
        modalCustomizer.classList.remove('active');
    });

    // 7. Render Cart Slip
    function renderCartSlip() {
        if (cart.length === 0) {
            emptyCartView.style.display = 'flex';
            cartItemsScroll.innerHTML = '';
            cartCountBadge.textContent = '0';
            totalItemsCount.textContent = '0 article(s)';
            grandTotalDisplay.textContent = '0.00 DH';
            btnValidateOrder.disabled = true;
            updateBoxQtyBadges();
            return;
        }

        emptyCartView.style.display = 'none';
        cartItemsScroll.innerHTML = '';

        let totalAmount = 0;
        let totalCount = 0;

        cart.forEach((item, index) => {
            const lineTotal = item.prix_unitaire * item.quantite;
            totalAmount += lineTotal;
            totalCount += item.quantite;

            const card = document.createElement('div');
            card.className = 'cart-line-card';

            // Modifiers pills
            let modHtml = '';
            item.preferences.forEach(p => {
                if (p.action === 'RETIRER') {
                    modHtml += `<span class="mod-badge retirer"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle; margin-right:3px;"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>Sans ${escapeHtml(p.nom)}</span>`;
                } else if (p.prix_supplement > 0) {
                    modHtml += `<span class="mod-badge ajouter-payant">+ ${escapeHtml(p.nom)} (+${p.prix_supplement.toFixed(2)} DH)</span>`;
                } else {
                    modHtml += `<span class="mod-badge ajouter-gratuit">+ ${escapeHtml(p.nom)}</span>`;
                }
            });

            const noteHtml = item.commentaire ? `<div class="line-note-tag">Note : ${escapeHtml(item.commentaire)}</div>` : '';

            card.innerHTML = `
                <div class="line-header">
                    <div class="line-title">${escapeHtml(item.nom)}</div>
                    <div class="line-price">${lineTotal.toFixed(2)} DH</div>
                </div>
                ${modHtml ? `<div class="line-modifiers-wrap">${modHtml}</div>` : ''}
                ${noteHtml}
                <div class="line-footer-controls">
                    <div class="stepper-wrap">
                        <button type="button" class="btn-step" onclick="window.stepCartItem(${index}, -1)">-</button>
                        <span class="step-value">${item.quantite}</span>
                        <button type="button" class="btn-step" onclick="window.stepCartItem(${index}, 1)">+</button>
                    </div>
                    <button type="button" class="btn-delete-line" onclick="window.removeCartLine(${index})" title="Supprimer">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
                    </button>
                </div>
            `;

            cartItemsScroll.appendChild(card);
        });

        cartCountBadge.textContent = totalCount.toString();
        totalItemsCount.textContent = `${totalCount} article(s)`;
        grandTotalDisplay.textContent = totalAmount.toFixed(2) + ' DH';
        btnValidateOrder.disabled = false;
        updateBoxQtyBadges();
    }

    // Synchronize In-Cart Quantities directly on the Sandwich Boxes
    function updateBoxQtyBadges() {
        const boxes = document.querySelectorAll('.sandwich-box');
        boxes.forEach(box => {
            const id = parseInt(box.getAttribute('data-id'), 10);
            if (!id) return;
            const count = cart.filter(c => c.id_sandwich === id).reduce((sum, c) => sum + c.quantite, 0);
            const wrap = box.querySelector('.box-hero-image-wrap');
            let badge = box.querySelector('.box-qty-in-cart');
            if (count > 0) {
                box.classList.add('has-in-cart');
                if (!badge && wrap) {
                    badge = document.createElement('div');
                    badge.className = 'box-qty-in-cart';
                    badge.title = `${count} dans la commande`;
                    wrap.appendChild(badge);
                }
                if (badge) badge.textContent = count;
            } else {
                box.classList.remove('has-in-cart');
                if (badge) badge.remove();
            }
        });
    }

    window.stepCartItem = function(index, delta) {
        if (cart[index]) {
            cart[index].quantite += delta;
            if (cart[index].quantite <= 0) {
                cart.splice(index, 1);
            }
            renderCartSlip();
        }
    };

    window.removeCartLine = function(index) {
        if (cart[index]) {
            cart.splice(index, 1);
            renderCartSlip();
        }
    };

    btnResetCart.addEventListener('click', () => {
        if (cart.length > 0 && confirm('Voulez-vous réinitialiser le panier en cours ?')) {
            cart = [];
            renderCartSlip();
        }
    });

    // 8. Modal Paiement & Cash Change Calculator
    const modalPaiement            = document.getElementById('modalPaiement');
    const payModalTotal            = document.getElementById('payModalTotal');
    const inputCashReceived        = document.getElementById('inputCashReceived');
    const valChangeToReturn        = document.getElementById('valChangeToReturn');
    const btnConfirmPaiementAndPrint = document.getElementById('btnConfirmPaiementAndPrint');
    const btnCancelPaiement        = document.getElementById('btnCancelPaiement');
    const btnClosePaiementModal    = document.getElementById('btnClosePaiementModal');

    // Utility: Calculate cart total
    function calculateCartTotal() {
        return cart.reduce((sum, item) => sum + item.prix_unitaire * item.quantite, 0);
    }

    // Utility: Close modals
    function closeAtelierModal() {
        if (modalCustomizer) modalCustomizer.classList.remove('active');
    }

    function closeTicketsModal() {
        if (modalTickets) modalTickets.classList.remove('active');
    }

    function openPaiementModal() {
        if (cart.length === 0) return;
        const total = calculateCartTotal();
        if (payModalTotal) payModalTotal.textContent = total.toFixed(2) + ' DH';
        if (inputCashReceived) inputCashReceived.value = '';
        if (valChangeToReturn) {
            valChangeToReturn.textContent = '0.00 DH';
            valChangeToReturn.style.color = '#065f46';
        }
        if (modalPaiement) modalPaiement.classList.add('active');
        setTimeout(() => { if (inputCashReceived) inputCashReceived.focus(); }, 120);
    }

    function closePaiementModal() {
        if (modalPaiement) modalPaiement.classList.remove('active');
    }

    function updateChangeCalculation() {
        if (!inputCashReceived || !valChangeToReturn) return;
        const total = calculateCartTotal();
        const received = parseFloat(inputCashReceived.value) || 0;
        const change = received - total;

        if (received === 0) {
            valChangeToReturn.textContent = '0.00 DH';
            valChangeToReturn.style.color = '#065f46';
        } else if (change >= 0) {
            valChangeToReturn.textContent = change.toFixed(2) + ' DH';
            valChangeToReturn.style.color = '#065f46';
        } else {
            valChangeToReturn.textContent = 'Manque ' + Math.abs(change).toFixed(2) + ' DH';
            valChangeToReturn.style.color = '#dc2626';
        }
    }

    if (inputCashReceived) {
        inputCashReceived.addEventListener('input', updateChangeCalculation);
    }

    window.setQuickCash = function(val) {
        if (!inputCashReceived) return;
        const total = calculateCartTotal();
        if (val === 'exact') {
            inputCashReceived.value = total.toFixed(2);
        } else {
            inputCashReceived.value = Number(val).toFixed(2);
        }
        updateChangeCalculation();
        inputCashReceived.focus();
    };

    if (btnClosePaiementModal) btnClosePaiementModal.addEventListener('click', closePaiementModal);
    if (btnCancelPaiement) btnCancelPaiement.addEventListener('click', closePaiementModal);

    btnValidateOrder.addEventListener('click', () => {
        if (cart.length === 0) return;
        openPaiementModal();
    });

    // Order Submission (F01, F02, F03, RG01-RG05)
    async function executeOrderSubmission() {
        if (cart.length === 0) return;

        if (btnConfirmPaiementAndPrint) {
            btnConfirmPaiementAndPrint.disabled = true;
            btnConfirmPaiementAndPrint.innerHTML = '<span>Enregistrement en cours...</span>';
        }

        const opSelect = document.getElementById('selectOperator');
        const payload = {
            id_restaurant: (window.TENANT_RESTAURANT_ID || 1),
            id_caissier: (opSelect && opSelect.value) ? parseInt(opSelect.value, 10) : 1,
            items: cart.map(item => ({
                id_sandwich: item.id_sandwich,
                quantite: item.quantite,
                commentaire: item.commentaire,
                preferences: item.preferences.map(p => ({
                    id_ingredient: p.id_ingredient,
                    action: p.action
                }))
            }))
        };

        try {
            const resp = await fetch('api/creer_commande.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });

            const res = await resp.json();

            if (res.success) {
                closePaiementModal();
                playSound('success');
                cart = [];
                renderCartSlip();
                showOrderSuccessOverlay(res.data.numero_commande, () => {
                    openDualTicketsModal(res.data);
                });
            } else {
                alert('Erreur validation : ' + res.message);
            }
        } catch (err) {
            alert('Erreur serveur : ' + err.message);
        } finally {
            if (btnConfirmPaiementAndPrint) {
                btnConfirmPaiementAndPrint.disabled = false;
                btnConfirmPaiementAndPrint.innerHTML = 'Encaisser &amp; Imprimer ➔';
            }
        }
    }

    if (btnConfirmPaiementAndPrint) {
        btnConfirmPaiementAndPrint.addEventListener('click', executeOrderSubmission);
    }

    // Keyboard Shortcuts: Numpad 1-9, +, -, Enter, Escape, F8
    document.addEventListener('keydown', (e) => {
        const isInputFocused = (document.activeElement && (document.activeElement.tagName === 'INPUT' || document.activeElement.tagName === 'TEXTAREA'));

        // If in Payment modal
        if (modalPaiement && modalPaiement.classList.contains('active')) {
            if (e.key === 'Enter') {
                e.preventDefault();
                executeOrderSubmission();
                return;
            }
            if (e.key === 'Escape') {
                e.preventDefault();
                closePaiementModal();
                return;
            }
        }

        // Close any modal on Escape
        if (e.key === 'Escape') {
            closeAtelierModal();
            closeTicketsModal();
            closePaiementModal();
            return;
        }

        // F8 to open payment
        if (e.key === 'F8') {
            e.preventDefault();
            if (cart.length > 0) openPaiementModal();
            return;
        }

        // Hotkeys 1-9 for quick add if not typing in input
        if (!isInputFocused && !modalCustomizer.classList.contains('active') && (!modalPaiement || !modalPaiement.classList.contains('active'))) {
            const num = parseInt(e.key);
            if (!isNaN(num) && num >= 1 && num <= 9) {
                const s = catalog.sandwiches[num - 1];
                if (s && parseInt(s.disponible ?? 1) === 1) {
                    e.preventDefault();
                    addDirectToCart(s);
                }
            } else if (e.key === '+' || e.key === 'Add') {
                if (cart.length > 0) {
                    e.preventDefault();
                    window.stepCartItem(cart.length - 1, 1);
                }
            } else if (e.key === '-' || e.key === 'Subtract') {
                if (cart.length > 0) {
                    e.preventDefault();
                    window.stepCartItem(cart.length - 1, -1);
                }
            }
        }
    });

    // 9. Display Dual-Tickets Modal (RG04 / F03)
    function openDualTicketsModal(order) {
        // Customer Receipt
        let clientLines = '';
        order.items.forEach(it => {
            const tot = (it.prix_unitaire * it.quantite).toFixed(2);
            clientLines += `
                <div class="slip-row">
                    <span style="font-weight:bold;">${it.quantite}x ${escapeHtml(it.nom)}</span>
                    <span>${tot} DH</span>
                </div>
            `;
            if (it.preferences && it.preferences.length > 0) {
                it.preferences.forEach(p => {
                    if (p.action === 'RETIRER') {
                        clientLines += `<div style="color:#b91c1c; font-size:11px; padding-left:8px;">- Sans ${escapeHtml(p.nom)}</div>`;
                    } else if (p.prix_applique > 0) {
                        clientLines += `<div style="color:#15803d; font-size:11px; padding-left:8px; font-weight:bold;">+ ${escapeHtml(p.nom)} (+${parseFloat(p.prix_applique).toFixed(2)} DH)</div>`;
                    } else {
                        clientLines += `<div style="color:#0369a1; font-size:11px; padding-left:8px;">+ ${escapeHtml(p.nom)}</div>`;
                    }
                });
            }
            if (it.commentaire) {
                clientLines += `<div style="font-style:italic; font-size:11px; padding-left:8px;">* ${escapeHtml(it.commentaire)}</div>`;
            }
        });

        ticketClientBox.innerHTML = `
            <div class="slip-header">
                <div class="slip-title">M3i9dat Ba M'Barek</div>
                <div>Spécialités Maâkouda Traditionnelle • Meknès</div>
                <div class="slip-order-num">COMMANDE N° ${escapeHtml(order.numero_commande)}</div>
                <div style="font-size:11px;">Date : ${escapeHtml(order.date_commande)}</div>
                <div style="font-size:11px;">Caissier : ${escapeHtml(order.caissier)}</div>
            </div>
            <div>
                ${clientLines}
                <div class="slip-total">
                    <span>TOTAL PAYÉ (TTC) :</span>
                    <span>${parseFloat(order.montant_total).toFixed(2)} DH</span>
                </div>
            </div>
            <div class="slip-footer">
                <div>Merci de votre confiance !</div>
                <div>Gardez ce ticket pour récupérer votre commande N° ${escapeHtml(order.numero_commande)}.</div>
            </div>
        `;

        // Kitchen Order Ticket
        let kitchenLines = '';
        order.items.forEach(it => {
            kitchenLines += `
                <div style="margin-bottom:10px; padding-bottom:8px; border-bottom:1px dashed #94a3b8;">
                    <div style="font-size:15px; font-weight:900;">
                        [ ${it.quantite} ] ${escapeHtml(it.nom).toUpperCase()}
                    </div>
            `;
            if (it.preferences && it.preferences.length > 0) {
                it.preferences.forEach(p => {
                    if (p.action === 'RETIRER') {
                        kitchenLines += `<div style="color:#b91c1c; font-weight:900; font-size:12px;">[SANS] : ${escapeHtml(p.nom).toUpperCase()}</div>`;
                    } else if (p.prix_applique > 0) {
                        kitchenLines += `<div style="color:#15803d; font-weight:900; font-size:12px;">[+EXTRA] : ${escapeHtml(p.nom).toUpperCase()}</div>`;
                    } else {
                        kitchenLines += `<div style="color:#0369a1; font-weight:bold; font-size:12px;">[SAUCE] : ${escapeHtml(p.nom)}</div>`;
                    }
                });
            }
            if (it.commentaire) {
                kitchenLines += `<div style="background:#fef08a; padding:2px 5px; font-weight:bold; font-size:11px; margin-top:3px;">NOTE : ${escapeHtml(it.commentaire)}</div>`;
            }
            kitchenLines += `</div>`;
        });

        ticketKitchenBox.innerHTML = `
            <div class="slip-header">
                <div class="slip-title" style="color:#b91c1c;">BON DE CUISINE</div>
                <div class="slip-order-num kitchen">COMMANDE N° ${escapeHtml(order.numero_commande)}</div>
                <div style="font-size:11px;">Heure : ${escapeHtml(order.date_commande)}</div>
                <div style="font-size:11px; font-weight:bold;">Statut : EN PREPARATION</div>
            </div>
            <div>
                ${kitchenLines}
            </div>
            <div class="slip-footer" style="font-weight:900; font-size:12px;">
                COMMANDE A PREPARER
            </div>
        `;

        const modalTicketsTitle = modalTickets.querySelector('.modal-item-title');
        if (modalTicketsTitle) {
            modalTicketsTitle.textContent = `Commande N° ${order.numero_commande} Enregistrée !`;
        }

        modalTickets.classList.add('active');
    }

    btnCloseTicketsModal.addEventListener('click', () => {
        modalTickets.classList.remove('active');
    });

    btnNewOrderFromTickets.addEventListener('click', () => {
        modalTickets.classList.remove('active');
    });

    btnPrintDualTickets.addEventListener('click', () => {
        window.print();
    });

    // Success Overlay: fullscreen green checkmark before ticket modal
    function showOrderSuccessOverlay(numeroCommande, callback) {
        const overlay = document.createElement('div');
        overlay.style.cssText = `
            position: fixed; inset: 0; z-index: 9998;
            background: rgba(21, 128, 61, 0.95);
            backdrop-filter: blur(6px);
            display: flex; flex-direction: column;
            align-items: center; justify-content: center;
            gap: 16px;
            animation: overlayFadeIn 0.25s ease;
        `;
        overlay.innerHTML = `
            <div style="color:#ffffff; animation: checkPop 0.4s cubic-bezier(0.34,1.56,0.64,1) 0.1s both;">
                <svg width="72" height="72" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10" stroke-width="1.8"/>
                    <polyline points="16 9 10.5 15 8 12.5"/>
                </svg>
            </div>
            <div style="font-size: 32px; font-weight: 900; color: #fff; letter-spacing: -0.5px;">
                Commande N° ${escapeHtml(String(numeroCommande))} enregistrée !
            </div>
            <div style="font-size: 16px; color: rgba(255,255,255,0.8); font-weight: 600;">
                Envoyée en cuisine · Tickets en cours de génération...
            </div>
        `;
        document.body.appendChild(overlay);

        // Add keyframes if not already present
        if (!document.getElementById('success-overlay-styles')) {
            const style = document.createElement('style');
            style.id = 'success-overlay-styles';
            style.textContent = `
                @keyframes overlayFadeIn { from { opacity: 0; } to { opacity: 1; } }
                @keyframes checkPop { from { transform: scale(0); opacity: 0; } to { transform: scale(1); opacity: 1; } }
            `;
            document.head.appendChild(style);
        }

        setTimeout(() => {
            overlay.style.opacity = '0';
            overlay.style.transition = 'opacity 0.3s ease';
            setTimeout(() => {
                overlay.remove();
                if (callback) callback();
            }, 300);
        }, 1400);
    }

    function escapeHtml(str) {
        if (!str) return '';
        const d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }

    // Tablet Fullscreen Mode Toggle (HTML5 Fullscreen API)
    window.toggleTabletFullscreen = function() {
        const isFs = !!(document.fullscreenElement || document.webkitFullscreenElement);
        if (!isFs) {
            const el = document.documentElement;
            if (el.requestFullscreen) {
                el.requestFullscreen().catch(() => {});
            } else if (el.webkitRequestFullscreen) {
                el.webkitRequestFullscreen();
            }
        } else {
            if (document.exitFullscreen) {
                document.exitFullscreen().catch(() => {});
            } else if (document.webkitExitFullscreen) {
                document.webkitExitFullscreen();
            }
        }
    };

    function updateFullscreenUI() {
        const isFs = !!(document.fullscreenElement || document.webkitFullscreenElement);
        const enterIcon = document.getElementById('iconEnterFullscreen');
        const exitIcon  = document.getElementById('iconExitFullscreen');
        const label     = document.getElementById('labelFullscreen');
        if (enterIcon) enterIcon.style.display = isFs ? 'none' : 'inline-block';
        if (exitIcon)  exitIcon.style.display  = isFs ? 'inline-block' : 'none';
        if (label)     label.textContent = isFs ? 'Réduire' : 'Plein Écran';
    }

    document.addEventListener('fullscreenchange', updateFullscreenUI);
    document.addEventListener('webkitfullscreenchange', updateFullscreenUI);

    // Launch!
    fetchCatalog();
});
