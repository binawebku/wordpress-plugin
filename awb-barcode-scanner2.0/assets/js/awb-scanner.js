/**
 * AWB Scanner JavaScript - Updated for stable cross-platform support
 * Compatible with the new stable zxing.min.js
 * Version: 1.6.1
 */

document.addEventListener('DOMContentLoaded', function() {
    'use strict';

    // Check if we're on a page with the scanner
    const scannerRoot = document.getElementById('awb-scanner-root');
    if (!scannerRoot) return;

    class AWBScannerApp {
        constructor() {
            this.isScanning = false;
            this.stream = null;
            this.reader = null;
            this.torch = null;
            this.initializeElements();
            this.setupEventListeners();
            this.setupBeepAudio();
            this.initializeReader();
        }

        initializeElements() {
            this.video = document.getElementById('awb-video');
            this.startBtn = document.getElementById('awb-start');
            this.stopBtn = document.getElementById('awb-stop');
            this.flashBtn = document.getElementById('awb-flash');
            this.barcodeInput = document.getElementById('awb-barcode');
            this.photosInput = document.getElementById('awb-photos');
            this.previewsDiv = document.getElementById('awb-previews');
            this.statusSelect = document.getElementById('awb-status-select');
            this.contentInput = document.getElementById('awb-content-input');
            this.submitBtn = document.getElementById('awb-submit');
            this.statusDiv = document.getElementById('awb-status');
            this.overlay = document.getElementById('awb-overlay');
        }

        setupEventListeners() {
            this.startBtn.addEventListener('click', () => this.startScanning());
            this.stopBtn.addEventListener('click', () => this.stopScanning());
            this.flashBtn.addEventListener('click', () => this.toggleFlash());
            this.photosInput.addEventListener('change', () => this.handlePhotoSelection());
            this.submitBtn.addEventListener('click', () => this.submitForm());
            this.barcodeInput.addEventListener('input', () => this.validateForm());
            
            // Handle page visibility changes
            document.addEventListener('visibilitychange', () => {
                if (document.hidden && this.isScanning) {
                    this.stopScanning();
                }
            });
        }

        setupBeepAudio() {
            this.beepEnabled = AWB_SCANNER_VARS.settings.beep;
            this.beepVolume = AWB_SCANNER_VARS.settings.beep_volume;
            
            if (this.beepEnabled) {
                this.audioContext = null;
                try {
                    this.audioContext = new (window.AudioContext || window.webkitAudioContext)();
                } catch (e) {
                    console.warn('AudioContext not supported:', e);
                }
            }
        }

        playBeep() {
            if (!this.beepEnabled || !this.audioContext) return;
            
            try {
                const oscillator = this.audioContext.createOscillator();
                const gainNode = this.audioContext.createGain();
                
                oscillator.connect(gainNode);
                gainNode.connect(this.audioContext.destination);
                
                oscillator.frequency.setValueAtTime(800, this.audioContext.currentTime);
                gainNode.gain.setValueAtTime(this.beepVolume, this.audioContext.currentTime);
                gainNode.gain.exponentialRampToValueAtTime(0.001, this.audioContext.currentTime + 0.1);
                
                oscillator.start(this.audioContext.currentTime);
                oscillator.stop(this.audioContext.currentTime + 0.1);
            } catch (e) {
                console.warn('Beep failed:', e);
            }
        }

        async initializeReader() {
            try {
                // Load the ZXing library if not already loaded
                if (typeof ZXing === 'undefined') {
                    await this.loadZXingLibrary();
                }

                // Initialize the multi-format reader
                this.reader = new ZXing.BrowserMultiFormatReader();
                console.log('ZXing reader initialized successfully');
                
                this.updateStatus('Scanner ready - tap to start');
                this.startBtn.disabled = false;
            } catch (error) {
                console.error('Failed to initialize ZXing reader:', error);
                this.updateStatus('Scanner initialization failed: ' + error.message);
                this.startBtn.disabled = true;
            }
        }

        async loadZXingLibrary() {
            return new Promise((resolve, reject) => {
                if (typeof ZXing !== 'undefined') {
                    resolve();
                    return;
                }

                const script = document.createElement('script');
                const pluginUrl = AWB_SCANNER_VARS.plugin_url || '';
                script.src = pluginUrl + '/assets/vendor/zxing.min.js';
                
                script.onload = () => {
                    console.log('ZXing library loaded successfully');
                    resolve();
                };
                
                script.onerror = () => {
                    console.error('Failed to load ZXing library');
                    reject(new Error('Failed to load ZXing library'));
                };
                
                document.head.appendChild(script);
            });
        }

        async startScanning() {
            if (this.isScanning || !this.reader) return;

            try {
                this.updateStatus('Starting camera...');
                
                // Get camera constraints
                const constraints = this.getCameraConstraints();
                
                // Show video container
                this.video.style.display = 'block';
                this.overlay.style.display = 'block';
                
                // Start scanning
                this.stream = await this.reader.decodeFromVideoDevice(
                    undefined, // Let the reader choose the best camera
                    this.video,
                    (result, error) => {
                        if (result) {
                            this.handleScanResult(result);
                        } else if (error && !(error instanceof ZXing.NotFoundException)) {
                            console.error('Scan error:', error);
                        }
                    }
                );
                
                this.isScanning = true;
                this.startBtn.disabled = true;
                this.stopBtn.disabled = false;
                this.updateStatus('Scanning... Point camera at barcode');
                
                // Try to enable torch if requested
                this.initializeTorch();
                
            } catch (error) {
                console.error('Failed to start scanning:', error);
                this.updateStatus('Failed to start camera: ' + error.message);
                this.handleScanError(error);
            }
        }

        getCameraConstraints() {
            const rearOnly = AWB_SCANNER_VARS.settings.rear_only;
            const aspectRatio = AWB_SCANNER_VARS.settings.aspect_ratio || '16:9';
            
            let idealWidth = 1280;
            let idealHeight = 720;
            
            // Adjust dimensions based on aspect ratio
            switch (aspectRatio) {
                case '4:3':
                    idealWidth = 1024;
                    idealHeight = 768;
                    break;
                case '1:1':
                    idealWidth = 720;
                    idealHeight = 720;
                    break;
            }
            
            const constraints = {
                video: {
                    width: { ideal: idealWidth, max: 1920 },
                    height: { ideal: idealHeight, max: 1080 },
                    frameRate: { ideal: 30, max: 60 }
                }
            };
            
            if (rearOnly) {
                constraints.video.facingMode = { ideal: 'environment' };
            }
            
            return constraints;
        }

        async initializeTorch() {
            try {
                if (this.stream && this.stream.getVideoTracks().length > 0) {
                    const track = this.stream.getVideoTracks()[0];
                    const capabilities = track.getCapabilities();
                    
                    if (capabilities.torch) {
                        this.torch = track;
                        this.flashBtn.style.display = 'block';
                        console.log('Torch available');
                    }
                }
            } catch (error) {
                console.log('Torch not available:', error.message);
            }
        }

        async toggleFlash() {
            if (!this.torch) return;
            
            try {
                const currentConstraints = this.torch.getConstraints();
                const torchEnabled = currentConstraints.torch;
                
                await this.torch.applyConstraints({
                    torch: !torchEnabled
                });
                
                this.flashBtn.setAttribute('aria-pressed', (!torchEnabled).toString());
                this.flashBtn.textContent = !torchEnabled ? 'Flash On' : 'Flash';
                
            } catch (error) {
                console.error('Failed to toggle flash:', error);
            }
        }

        handleScanResult(result) {
            if (!result || !result.getText()) return;
            
            const barcodeText = result.getText().trim();
            console.log('Barcode detected:', barcodeText);
            
            // Update UI
            this.barcodeInput.value = barcodeText;
            this.playBeep();
            this.updateStatus(`Detected: ${barcodeText}`);
            this.validateForm();
            
            // Add visual feedback
            this.showScanSuccess();
            
            // Auto-stop scanning after successful detection
            setTimeout(() => {
                this.stopScanning();
            }, 1000);
        }

        showScanSuccess() {
            const overlay = this.overlay;
            const reticle = overlay.querySelector('.awb-reticle');
            
            if (reticle) {
                reticle.style.borderColor = '#00ff00';
                reticle.style.boxShadow = '0 0 20px rgba(0, 255, 0, 0.8)';
                
                setTimeout(() => {
                    reticle.style.borderColor = '';
                    reticle.style.boxShadow = '';
                }, 1500);
            }
        }

        stopScanning() {
            if (!this.isScanning) return;
            
            try {
                if (this.reader) {
                    this.reader.reset();
                }
                
                if (this.stream) {
                    this.stream.getTracks().forEach(track => track.stop());
                    this.stream = null;
                }
                
                this.video.style.display = 'none';
                this.overlay.style.display = 'none';
                this.flashBtn.style.display = 'none';
                
                this.isScanning = false;
                this.torch = null;
                this.startBtn.disabled = false;
                this.stopBtn.disabled = true;
                
                this.updateStatus('Scanner stopped');
                
            } catch (error) {
                console.error('Error stopping scanner:', error);
            }
        }

        handleScanError(error) {
            console.error('Scan error:', error);
            
            let message = 'Camera error';
            if (error.name === 'NotAllowedError') {
                message = 'Camera permission denied';
            } else if (error.name === 'NotFoundError') {
                message = 'No camera found';
            } else if (error.name === 'NotReadableError') {
                message = 'Camera is being used by another application';
            }
            
            this.updateStatus(message);
        }

        handlePhotoSelection() {
            const files = this.photosInput.files;
            this.previewsDiv.innerHTML = '';
            
            if (files.length === 0) return;
            
            Array.from(files).forEach((file, index) => {
                if (file.type.startsWith('image/')) {
                    const reader = new FileReader();
                    reader.onload = (e) => {
                        const img = document.createElement('img');
                        img.src = e.target.result;
                        img.className = 'awb-preview-image';
                        img.style.maxWidth = '100px';
                        img.style.maxHeight = '100px';
                        img.style.margin = '5px';
                        img.style.borderRadius = '4px';
                        this.previewsDiv.appendChild(img);
                    };
                    reader.readAsDataURL(file);
                }
            });
            
            this.validateForm();
        }

        validateForm() {
            const hasBarcode = this.barcodeInput.value.trim().length > 0;
            this.submitBtn.disabled = !hasBarcode;
            
            if (hasBarcode) {
                this.submitBtn.classList.add('awb-btn-primary');
                this.submitBtn.classList.remove('awb-btn-outline');
            } else {
                this.submitBtn.classList.remove('awb-btn-primary');
                this.submitBtn.classList.add('awb-btn-outline');
            }
        }

        async submitForm() {
            const barcode = this.barcodeInput.value.trim();
            if (!barcode) {
                this.updateStatus('Please scan or enter a barcode first');
                return;
            }
            
            this.submitBtn.disabled = true;
            this.updateStatus('Saving...');
            
            try {
                const formData = new FormData();
                formData.append('action', 'awb_create_post');
                formData.append('barcode', barcode);
                formData.append('content', this.contentInput.value.trim());
                formData.append('post_status', this.statusSelect.value);
                formData.append(AWB_SCANNER_VARS.nonce_name, AWB_SCANNER_VARS.nonce);
                
                // Add photos if selected
                const files = this.photosInput.files;
                if (files.length > 0) {
                    Array.from(files).forEach((file, index) => {
                        formData.append(`photos[${index}]`, file);
                    });
                }
                
                const response = await fetch(AWB_SCANNER_VARS.ajax_url, {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    this.updateStatus(result.data.message);
                    this.resetForm();
                    this.playBeep();
                    
                    // Show success message with link
                    if (result.data.view_link) {
                        this.showSuccessMessage(result.data.view_link);
                    }
                } else {
                    this.updateStatus('Error: ' + (result.data?.message || 'Save failed'));
                }
                
            } catch (error) {
                console.error('Submit error:', error);
                this.updateStatus('Network error: ' + error.message);
            } finally {
                this.submitBtn.disabled = false;
            }
        }

        showSuccessMessage(viewLink) {
            const message = document.createElement('div');
            message.className = 'awb-success-message';
            message.innerHTML = `
                <div style="background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 15px; border-radius: 6px; margin: 15px 0;">
                    <strong>✓ AWB saved successfully!</strong><br>
                    <a href="${viewLink}" target="_blank" style="color: #155724; text-decoration: underline;">View Post</a>
                </div>
            `;
            
            this.statusDiv.appendChild(message);
            
            setTimeout(() => {
                if (message.parentNode) {
                    message.parentNode.removeChild(message);
                }
            }, 5000);
        }

        resetForm() {
            this.barcodeInput.value = '';
            this.contentInput.value = '';
            this.photosInput.value = '';
            this.previewsDiv.innerHTML = '';
            this.validateForm();
        }

        updateStatus(message) {
            this.statusDiv.textContent = message;
            console.log('Status:', message);
        }
    }

    // Initialize the scanner app
    try {
        new AWBScannerApp();
        console.log('AWB Scanner initialized successfully');
    } catch (error) {
        console.error('Failed to initialize AWB Scanner:', error);
    }
});

// Export for debugging
if (typeof window !== 'undefined') {
    window.AWBScannerApp = AWBScannerApp;
}

// --- AWB duplicate pre-check + beeps + banner ---
function awbBeep(freq=800, dur=120, vol=0.2, reps=1, gap=120){
  try{
    const ctx = new (window.AudioContext||window.webkitAudioContext)();
    let when = ctx.currentTime;
    for(let i=0;i<reps;i++){
      const o = ctx.createOscillator();
      const g = ctx.createGain();
      o.type = 'sine'; o.frequency.value = freq;
      g.gain.value = vol;
      o.connect(g); g.connect(ctx.destination);
      o.start(when);
      o.stop(when + dur/1000);
      when += (dur+gap)/1000;
    }
  }catch(e){/* no audio */}
}

function awbBanner(msg, type='warn'){
  let el = document.getElementById('awb-dup-banner');
  if(!el){
    el = document.createElement('div');
    el.id='awb-dup-banner';
    el.style.position='fixed';
    el.style.left='50%'; el.style.top='20px';
    el.style.transform='translateX(-50%)';
    el.style.zIndex='99999';
    el.style.padding='10px 14px';
    el.style.borderRadius='10px';
    el.style.background = type==='warn' ? '#ffefc6' : '#d1ffd6';
    el.style.border='1px solid rgba(0,0,0,.1)';
    el.style.boxShadow='0 8px 24px rgba(0,0,0,.18)';
    el.style.fontFamily='system-ui, -apple-system, Segoe UI, Roboto, Poppins, Arial';
    el.style.fontSize='14px';
    document.body.appendChild(el);
  }
  el.textContent = msg;
  el.style.display='block';
  clearTimeout(el._t);
  el._t = setTimeout(()=>{ el.style.display='none'; }, 1800);
}

async function awbCheckDuplicate(barcode){
  try{
    const fd = new FormData();
    fd.set('action','awb_check_barcode');
    fd.set('barcode', awbNormalize(barcode));
    // optional: if you use custom post type, set it here
    // fd.set('post_type','post');
    const res = await fetch( (typeof awbAjax!=='undefined'? awbAjax.url : '/wp-admin/admin-ajax.php'), {
      method:'POST', credentials:'same-origin', body: fd
    });
    const j = await res.json();
    return j?.success && j?.data?.exists ? j.data : {exists:false};
  }catch(e){ return {exists:false}; }
}



// Bind Save button(s) even if IDs differ
document.addEventListener('DOMContentLoaded', () => {
  const form = document.getElementById('awb-form') || document.querySelector('form[data-awb-form]') || document.querySelector('form.awb-form');
  function currentBarcode(){
    const el = (form && form.querySelector('[name=barcode]')) || document.querySelector('[name=barcode]') || document.querySelector('#barcode');
    return el ? (el.value||'').trim() : '';
  }
  const clickHandler = (e) => {
    const t = e.target;
    if (t.matches('#awb-save-btn, [data-awb-save], .awb-save-btn')){
      e.preventDefault();
      const bc = currentBarcode();
      submitForm(bc);
    }
  };
  document.addEventListener('click', clickHandler);

  // If form submits normally, intercept and use AJAX
  if (form){
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      submitForm(currentBarcode());
    });
  }
});



function awbNormalize(barcode){
  return (barcode||'').toString().trim().toUpperCase().replace(/[^A-Z0-9]/g, '');
}



// --- AWB: TTS + vibration + fullscreen alert for duplicates ---
function awbSpeak(text){
  try{
    if (!('speechSynthesis' in window)) return;
    const u = new SpeechSynthesisUtterance(text);
    u.rate = 1.0; u.pitch = 1.0;
    window.speechSynthesis.cancel();
    window.speechSynthesis.speak(u);
  }catch(e){}
}

function awbVibrate(ms=180){ try{ if (navigator.vibrate) navigator.vibrate(ms); }catch(e){} }

function awbFullAlert(msg){
  let el = document.getElementById('awb-dup-full');
  if(!el){
    el = document.createElement('div');
    el.id = 'awb-dup-full';
    el.style.position='fixed';
    el.style.inset='0';
    el.style.display='grid';
    el.style.placeItems='center';
    el.style.background='rgba(220,0,0,0.18)';
    el.style.backdropFilter='blur(2px)';
    el.style.zIndex='999999';
    const inner = document.createElement('div');
    inner.style.padding='16px 22px';
    inner.style.borderRadius='14px';
    inner.style.background='#fff';
    inner.style.boxShadow='0 10px 30px rgba(0,0,0,.25)';
    inner.style.fontFamily='Inter, system-ui, -apple-system, Segoe UI, Roboto, Poppins, Arial';
    inner.style.fontSize='22px';
    inner.style.fontWeight='700';
    inner.style.color='#b00020';
    inner.style.textTransform='uppercase';
    inner.id='awb-dup-full-inner';
    el.appendChild(inner);
    document.body.appendChild(el);
  }
  const inner = document.getElementById('awb-dup-full-inner');
  inner.textContent = msg;
  el.style.opacity='0'; el.style.display='grid';
  // simple fade
  el.animate([{opacity:0},{opacity:1}], {duration:120, fill:'forwards'});
  clearTimeout(el._t);
  el._t = setTimeout(()=>{
    el.animate([{opacity:1},{opacity:0}], {duration:150, fill:'forwards'});
    setTimeout(()=>{ el.style.display='none'; }, 160);
  }, 1200);
}



// --- AWB: Instant duplicate alert immediately on scan (before Save) ---
function awbBindInstantDupCheck(){
  const input = document.querySelector('[name=barcode], #barcode');
  if(!input) return;

  let lastAnnounced = '';
  async function onUpdate(raw){
    const norm = awbNormalize(raw||'');
    if(!norm || norm.length < 5) return;
    if (AWBScannerGuards && AWBScannerGuards.seenRecently(norm)) return;
    if (norm === lastAnnounced) return;

    const dup = await awbCheckDuplicate(norm);
    if (dup && dup.exists){ awbShowHardWarning('DUPLICATE BARCODE');
      lastAnnounced = norm;
      if (typeof awbBeep === 'function') awbBeep(1000,120,0.25,2,120);
      if (typeof awbVibrate === 'function') awbVibrate(200);
      if (typeof awbBanner === 'function') awbBanner('DUPLICATE BARCODE DETECTED', 'warn');
      if (typeof awbFullAlert === 'function') awbFullAlert('DUPLICATE BARCODE DETECTED');
      if (typeof awbSpeak === 'function') awbSpeak('Duplicate barcode detected');
      if (AWBScannerGuards && AWBScannerGuards.remember) AWBScannerGuards.remember(norm);
    }
  }

  // Input/change events
  input.addEventListener('input', e => onUpdate(e.target.value));
  input.addEventListener('change', e => onUpdate(e.target.value));

  // Intercept programmatic .value set (ZXing often sets value directly)
  try {
    const desc = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');
    if (desc && desc.configurable) {
      Object.defineProperty(input, 'value', {
        get(){ return desc.get.call(this); },
        set(v){
          desc.set.call(this, v);
          onUpdate(v);
        }
      });
    }
  } catch(e){ /* ignore */ }

  // First run if prefilled
  if (input.value) onUpdate(input.value);
}

document.addEventListener('DOMContentLoaded', awbBindInstantDupCheck);



// --- AWB: ZXing decode hook to block duplicates BEFORE app sees them ---
(function(){
  function hookZXing(){
    try {
      if (!window.ZXing || !ZXing.BrowserMultiFormatReader) return false;
      const proto = ZXing.BrowserMultiFormatReader.prototype;
      if (!proto || proto.__awb_hooked) return !!proto;
      const orig = proto.decodeFromVideoDevice;
      if (typeof orig !== 'function') return false;
      proto.decodeFromVideoDevice = function(deviceId, videoElement, callback){
        const wrapped = async (result, err, controls) => {
          try{
            if (result && result.text){
              const norm = (typeof awbNormalize === 'function') ? awbNormalize(result.text) : (result.text||'').trim().toUpperCase().replace(/[^A-Z0-9]/g,'');
              if (norm && norm.length >= 5){
                const dup = await (typeof awbCheckDuplicate==='function' ? awbCheckDuplicate(norm) : Promise.resolve({exists:false}));
                if (dup && dup.exists){ awbShowHardWarning('DUPLICATE BARCODE');
                  // Immediate alert & DO NOT forward to app callback
                  if (typeof awbBeep==='function') awbBeep(1000,120,0.25,2,120); awbShowHardWarning('DUPLICATE BARCODE');
                  if (typeof awbVibrate==='function') awbVibrate(200);
                  if (typeof awbBanner==='function') awbBanner('DUPLICATE BARCODE DETECTED', 'warn');
                  if (typeof awbFullAlert==='function') awbFullAlert('DUPLICATE BARCODE DETECTED');
                  if (typeof awbSpeak==='function') awbSpeak('Duplicate barcode detected');
                  if (window.AWBScannerGuards && AWBScannerGuards.remember) AWBScannerGuards.remember(norm);
                  return; // swallow duplicate
                }
              }
            }
          }catch(e){ /* ignore */ }
          if (typeof callback === 'function') return callback(result, err, controls);
        };
        return orig.call(this, deviceId, videoElement, wrapped);
      };
      proto.__awb_hooked = true;
      return true;
    } catch(e){ return false; }
  }
  if (!hookZXing()){
    // try again after libs load
    document.addEventListener('DOMContentLoaded', hookZXing);
    setTimeout(hookZXing, 1500);
  }
})();



// --- AWB hard warning overlay (no dependencies) ---
function awbShowHardWarning(msg){
  try{
    let el = document.getElementById('awb-hardwarn');
    if(!el){
      el = document.createElement('div');
      el.id = 'awb-hardwarn';
      el.style.position = 'fixed';
      el.style.left = '50%';
      el.style.top = '12%';
      el.style.transform = 'translateX(-50%)';
      el.style.zIndex = '2147483647';
      el.style.background = '#B00020';
      el.style.color = '#fff';
      el.style.padding = '12px 18px';
      el.style.borderRadius = '12px';
      el.style.fontFamily = 'system-ui,-apple-system,Segoe UI,Roboto,Poppins,Arial';
      el.style.fontSize = '18px';
      el.style.fontWeight = '800';
      el.style.letterSpacing = '0.5px';
      el.style.boxShadow = '0 10px 30px rgba(0,0,0,.35)';
      el.style.textTransform = 'uppercase';
      el.style.pointerEvents = 'none';
      el.style.opacity = '0';
      document.body.appendChild(el);
    }
    el.textContent = msg || 'DUPLICATE BARCODE';
    el.style.display = 'block';
    el.animate([{opacity:0},{opacity:1}], {duration:120, fill:'forwards'});
    clearTimeout(el._t);
    el._t = setTimeout(()=>{
      el.animate([{opacity:1},{opacity:0}], {duration:200, fill:'forwards'});
      setTimeout(()=>{ el.style.display='none'; }, 220);
    }, 1800);
  }catch(e){}
}

