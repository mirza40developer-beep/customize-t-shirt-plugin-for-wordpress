(() => {
  'use strict';
  const DEFAULT_LAYOUT = { x: 50, y: 45, scale: 100 };
  const FONTS = { sans: 'Arial, Helvetica, sans-serif', serif: 'Georgia, Times, serif', display: 'Impact, Haettenschweiler, "Arial Narrow Bold", sans-serif', mono: '"Courier New", monospace' };
  const SIZE_SCALES = { XS: 0.86, S: 0.94, M: 1, L: 1.06, XL: 1.12, '2XL': 1.18 };
  const layout = () => ({ ...DEFAULT_LAYOUT });
  const side = () => ({ text: '', textColour: '#151515', fontType: 'sans', font: 'sans', imageData: '', imageName: '', textLayout: layout(), imageLayout: layout() });
  const isPlaceholderText = text => ! text || String( text ).trim().toUpperCase() === 'YOUR DESIGN';
  const removeExtraTextButtons = root => {
    root.querySelectorAll('button').forEach( btn => {
      const text = btn.textContent && btn.textContent.trim().toLowerCase();
      if ( text && text.includes( 'add another text item' ) ) {
        btn.remove();
      }
    } );
  };
  const status = (root, message, type = '') => { const node = root.querySelector('.dropx-designer__status'); node.textContent = message || ''; node.className = `dropx-designer__status${type ? ` is-${type}` : ''}`; };

  function mount(root) {
    const state = { colour: '#f5f3ef', size: 'M', activeSide: 'front', selected: 'text', zoom: 1, pan: { x: 0, y: 0 }, sides: { front: side(), back: side() } };
    const el = {
      shirt: root.querySelector('.dropx-designer__shirt'), stage: root.querySelector('.dropx-designer__stage'), text: root.querySelector('.dropx-designer__print-text'), image: root.querySelector('.dropx-designer__print-image'), area: root.querySelector('.dropx-designer__print-area'), label: root.querySelector('.dropx-designer__side-label'), textarea: root.querySelector('.dropx-designer__text-input'), font: root.querySelector('.dropx-designer__font-select'), fontInput: root.querySelector('.dropx-designer__font-input'), colour: root.querySelector('.dropx-designer__text-colour'), upload: root.querySelector('.dropx-designer__upload-input'), artRow: root.querySelector('.dropx-designer__artwork-row'), artName: root.querySelector('.dropx-designer__artwork-name'), scale: root.querySelector('.dropx-designer__scale'), scaleValue: root.querySelector('.dropx-designer__scale-value'), add: root.querySelector('.dropx-designer__add-cart')
    };
    const current = () => state.sides[state.activeSide];
    const activeLayout = () => current()[state.selected === 'artwork' ? 'imageLayout' : 'textLayout'];
    const place = (node, values) => { node.style.left = `${values.x}%`; node.style.top = `${values.y}%`; node.style.transform = `translate(-50%, -50%) scale(${values.scale / 100})`; };

    async function buildPreview( sideState ) {
      try {
        const c = document.createElement('canvas');
        const W = 400, H = 450;
        c.width = W; c.height = H;
        const ctx = c.getContext('2d');
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, W, H);
        const shirtW = Math.round(W * 0.6);
        const shirtH = Math.round(H * 0.8);
        const shirtX = Math.round((W - shirtW) / 2);
        const shirtY = Math.round((H - shirtH) / 2 - 20);
        const r = 20;
        ctx.fillStyle = state.colour || '#f5f3ef';
        ctx.beginPath();
        ctx.moveTo(shirtX + r, shirtY);
        ctx.lineTo(shirtX + shirtW - r, shirtY);
        ctx.quadraticCurveTo(shirtX + shirtW, shirtY, shirtX + shirtW, shirtY + r);
        ctx.lineTo(shirtX + shirtW, shirtY + shirtH - r);
        ctx.quadraticCurveTo(shirtX + shirtW, shirtY + shirtH, shirtX + shirtW - r, shirtY + shirtH);
        ctx.lineTo(shirtX + r, shirtY + shirtH);
        ctx.quadraticCurveTo(shirtX, shirtY + shirtH, shirtX, shirtY + shirtH - r);
        ctx.lineTo(shirtX, shirtY + r);
        ctx.quadraticCurveTo(shirtX, shirtY, shirtX + r, shirtY);
        ctx.closePath();
        ctx.fill();
        const pa = { left: 0.284, top: 0.21, w: 0.432, h: 0.7 };
        const paX = shirtX + Math.round(shirtW * pa.left);
        const paY = shirtY + Math.round(shirtH * pa.top);
        const paW = Math.round(shirtW * pa.w);
        const paH = Math.round(shirtH * pa.h);
        if ( sideState.imageData ) {
          await new Promise(resolve => {
            const img = new Image();
            img.onload = () => {
              const layout = sideState.imageLayout || { x: 50, y: 50, scale: 100 };
              const imgW = Math.round(paW * (layout.scale / 100));
              const imgH = Math.round(img.height * (imgW / img.width));
              const cx = paX + Math.round((layout.x / 100) * paW);
              const cy = paY + Math.round((layout.y / 100) * paH);
              ctx.drawImage(img, cx - imgW / 2, cy - imgH / 2, imgW, imgH);
              resolve();
            };
            img.onerror = () => resolve();
            img.src = sideState.imageData;
          });
        }
        if ( sideState.text && String(sideState.text).trim() && String(sideState.text).trim().toUpperCase() !== 'YOUR DESIGN' ) {
          const layout = sideState.textLayout || { x: 50, y: 50, scale: 100 };
          const fontFamily = sideState.font || 'Arial';
          const size = Math.max(12, Math.round(28 * (layout.scale / 100)));
          ctx.fillStyle = sideState.textColour || '#151515';
          ctx.textAlign = 'center';
          ctx.textBaseline = 'middle';
          ctx.font = `${size}px ${fontFamily}`;
          const x = paX + Math.round((layout.x / 100) * paW);
          const y = paY + Math.round((layout.y / 100) * paH);
          const lines = String(sideState.text).split('\n');
          const lh = Math.round(size * 1.15);
          for (let i = 0; i < lines.length; i++) {
            ctx.fillText(lines[i], x, y + (i - (lines.length - 1) / 2) * lh);
          }
        }
        return c.toDataURL('image/png');
      } catch (e) {
        return '';
      }
    }

    function loadGoogleFont( family ) {
      if ( ! family ) return;
      const cleaned = family.trim().replace(/\s+/g, ' ');
      if ( ! /^[A-Za-z0-9 '\-]+$/.test( cleaned ) ) return;
      const normalized = cleaned.replace(/\s+/g, '+').replace(/'/g, '%27');
      const safeId = `dropx-google-font-${ normalized.replace(/[^A-Za-z0-9_\-]/g, '-') }`;
      if ( document.getElementById( safeId ) ) return;
      const link = document.createElement( 'link' );
      link.id = safeId;
      link.rel = 'stylesheet';
      link.href = `https://fonts.googleapis.com/css2?family=${ normalized }&display=swap`;
      document.head.appendChild( link );
    }

    function render() {
      const s = current();
      el.shirt.style.setProperty('--dropx-shirt-colour', state.colour);
      el.text.textContent = s.text || '';
      el.text.style.color = s.textColour;
      const fontFamily = s.fontType === 'custom' && s.font ? `'${ s.font }', sans-serif` : FONTS[ s.fontType ] || FONTS.sans;
      el.text.style.fontFamily = fontFamily;
      if ( s.fontType === 'custom' && s.font ) loadGoogleFont( s.font );
      el.text.hidden = ! s.text || isPlaceholderText( s.text );
      el.text.classList.toggle('is-selected', state.selected === 'text');
      place(el.text, s.textLayout);
      el.image.src = s.imageData || '';
      el.image.hidden = !s.imageData;
      el.image.classList.toggle('is-selected', state.selected === 'artwork');
      place(el.image, s.imageLayout);
      el.textarea.value = s.text;
      el.font.value = s.fontType;
      el.fontInput.value = s.fontType === 'custom' ? s.font : '';
      el.colour.value = s.textColour;
      el.artRow.hidden = !s.imageData;
      el.artName.textContent = s.imageName || '';
      const values = activeLayout();
      el.scale.value = values.scale;
      el.scaleValue.textContent = `${Math.round(values.scale)}%`;
      el.label.textContent = state.activeSide === 'front' ? 'Front print' : 'Back print';
      root.querySelectorAll('[data-side]').forEach(b => b.classList.toggle('is-active', b.dataset.side === state.activeSide));
      root.querySelectorAll('[data-colour]').forEach(b => b.classList.toggle('is-active', b.dataset.colour === state.colour));
      root.querySelectorAll('[data-size]').forEach(b => b.classList.toggle('is-active', b.dataset.size === state.size));

      // Apply shirt size scaling and zoom
      const sizeScale = SIZE_SCALES[ state.size ] || 1;
      const totalScale = sizeScale * (state.zoom || 1);
      el.shirt.style.transform = `translate(${state.pan.x}px, ${state.pan.y}px) scale(${ totalScale })`;
      removeExtraTextButtons( root );
    }

    root.querySelectorAll('[data-side]').forEach(b => b.addEventListener('click', () => { state.activeSide = b.dataset.side; state.selected = current().imageData ? 'artwork' : 'text'; render(); }));
    root.querySelectorAll('[data-colour]').forEach(b => b.addEventListener('click', () => { state.colour = b.dataset.colour; render(); }));
    root.querySelectorAll('[data-size]').forEach(b => b.addEventListener('click', () => { state.size = b.dataset.size; render(); }));
    root.querySelectorAll('.dropx-designer__zoom-btn').forEach(b => b.addEventListener('click', () => {
      const action = b.dataset.zoom;
      if ( action === 'in' ) state.zoom = Math.min(2, (state.zoom || 1) + 0.1);
      else if ( action === 'out' ) state.zoom = Math.max(0.6, (state.zoom || 1) - 0.1);
      else state.zoom = 1;
      render();
    }));
    el.textarea.addEventListener('input', () => { current().text = el.textarea.value.slice(0, 70); state.selected = 'text'; render(); });
    el.font.addEventListener('change', () => {
      const value = el.font.value;
      current().fontType = value;
      if ( value !== 'custom' ) {
        current().font = value;
      }
      state.selected = 'text';
      render();
    });
    el.fontInput.addEventListener('input', () => {
      current().fontType = 'custom';
      current().font = el.fontInput.value.trim();
      el.font.value = 'custom';
      state.selected = 'text';
      render();
    });
    el.colour.addEventListener('input', () => { current().textColour = el.colour.value; state.selected = 'text'; render(); });
    el.text.addEventListener('pointerdown', () => { state.selected = 'text'; render(); });
    el.image.addEventListener('pointerdown', () => { state.selected = 'artwork'; render(); });
    el.upload.addEventListener('change', () => {
      const file = el.upload.files && el.upload.files[0];
      if (!file) return;
      if (!['image/png', 'image/jpeg', 'image/webp'].includes(file.type) || file.size > 5 * 1024 * 1024) { status(root, window.DropXDesigner?.i18n?.uploadError || 'Choose an image up to 5 MB.', 'error'); el.upload.value = ''; return; }
      const reader = new FileReader();
      reader.onload = () => { current().imageData = reader.result; current().imageName = file.name; state.selected = 'artwork'; status(root, ''); render(); };
      reader.readAsDataURL(file);
    });
    root.querySelector('.dropx-designer__remove-artwork').addEventListener('click', () => { current().imageData = ''; current().imageName = ''; state.selected = 'text'; el.upload.value = ''; render(); });
    root.querySelector('.dropx-designer__reset-layout').addEventListener('click', () => { current().textLayout = layout(); current().imageLayout = layout(); render(); });
    el.scale.addEventListener('input', () => { activeLayout().scale = Number(el.scale.value); render(); });

    let dragging = null;
    el.area.addEventListener('pointerdown', event => {
      const item = event.target.closest('.dropx-designer__print-item');
      const isShirt = event.target.closest('.dropx-designer__shirt') && !item;
      if ( item && !item.hidden ) {
        state.selected = item.dataset.item;
        dragging = { id: event.pointerId, type: 'item', rect: el.area.getBoundingClientRect() };
      } else if ( isShirt && (state.zoom || 1) !== 1) {
        dragging = { id: event.pointerId, type: 'pan', startX: event.clientX, startY: event.clientY, panX: state.pan.x, panY: state.pan.y };
      } else {
        return;
      }
      el.area.setPointerCapture(event.pointerId);
      render();
      event.preventDefault();
    });
    el.area.addEventListener('pointermove', event => {
      if (!dragging || event.pointerId !== dragging.id) return;
      if ( dragging.type === 'item' ) {
        const values = activeLayout();
        values.x = Math.max(0, Math.min(100, ((event.clientX - dragging.rect.left) / dragging.rect.width) * 100));
        values.y = Math.max(0, Math.min(100, ((event.clientY - dragging.rect.top) / dragging.rect.height) * 100));
      } else if ( dragging.type === 'pan' ) {
        state.pan.x = dragging.panX + event.clientX - dragging.startX;
        state.pan.y = dragging.panY + event.clientY - dragging.startY;
      }
      render();
    });
    const endDrag = event => { if (!dragging || event.pointerId !== dragging.id) return; if (el.area.hasPointerCapture(event.pointerId)) el.area.releasePointerCapture(event.pointerId); dragging = null; };
    el.area.addEventListener('pointerup', endDrag); el.area.addEventListener('pointercancel', endDrag);

    el.add.addEventListener('click', async () => {
      const productId = Number(root.dataset.productId || 0);
      if (!productId || el.add.disabled || !window.DropXDesigner) return;
      const original = el.add.innerHTML;
      el.add.disabled = true;
      el.add.textContent = window.DropXDesigner.i18n.adding;
      status(root, '');
      try {
        const previewFront = await buildPreview(state.sides.front);
        const previewBack = await buildPreview(state.sides.back);

        const data = new FormData();
        data.append('action', 'dropx_add_tshirt_design');
        data.append('nonce', window.DropXDesigner.nonce);
        data.append('product_id', productId);
        data.append('design', JSON.stringify(state));
        if (previewFront) data.append('preview_front', previewFront);
        if (previewBack) data.append('preview_back', previewBack);
        const response = await fetch(window.DropXDesigner.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data });
        const result = await response.json();
        if (!result.success) throw new Error(result?.data?.message || 'Unable to add design.');
        status(root, result.data.message, 'success');
        if (result.data.cartUrl || window.DropXDesigner.cartUrl) window.location.assign(result.data.cartUrl || window.DropXDesigner.cartUrl);
      } catch (error) {
        status(root, error.message || window.DropXDesigner.i18n.cartError, 'error');
        el.add.disabled = false;
        el.add.innerHTML = original;
      }
    });
    render();
  }

  const init = () => document.querySelectorAll('.dropx-designer').forEach(mount);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();