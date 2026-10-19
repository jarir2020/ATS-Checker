(() => {
  'use strict';

  const encoder = new TextEncoder();
  const decoder = new TextDecoder('utf-8', {fatal:true});
  const ITERATIONS = 600000;
  let masterKey = null;
  let rawMasterKey = null;
  let metadata = null;

  function randomBytes(length) {
    const value = new Uint8Array(length);
    crypto.getRandomValues(value);
    return value;
  }

  function toBase64(bytes) {
    let binary = '';
    for (let offset = 0; offset < bytes.length; offset += 0x8000) {
      binary += String.fromCharCode(...bytes.subarray(offset, Math.min(offset + 0x8000, bytes.length)));
    }
    return btoa(binary);
  }

  function fromBase64(value) {
    const binary = atob(String(value || ''));
    const bytes = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
    return bytes;
  }

  async function derivePassphraseKey(passphrase, salt) {
    const passwordBytes = encoder.encode(passphrase);
    const source = await crypto.subtle.importKey('raw', passwordBytes, 'PBKDF2', false, ['deriveKey']);
    passwordBytes.fill(0);
    return crypto.subtle.deriveKey(
      {name:'PBKDF2', hash:'SHA-256', salt, iterations:ITERATIONS},
      source,
      {name:'AES-GCM', length:256},
      false,
      ['encrypt','decrypt']
    );
  }

  function aad(value) { return encoder.encode(value); }

  async function wrapKeyBytes(rawKey, wrappingKey, context) {
    const nonce = randomBytes(12);
    const ciphertext = await crypto.subtle.encrypt({name:'AES-GCM', iv:nonce, additionalData:aad(context), tagLength:128}, wrappingKey, rawKey);
    return {nonce:toBase64(nonce), ciphertext:toBase64(new Uint8Array(ciphertext))};
  }

  async function createCandidate(passphrase) {
    if (!window.isSecureContext || !crypto?.subtle) throw new Error('The encrypted vault requires HTTPS or localhost and a browser with Web Crypto support.');
    if (passphrase.length < 12) throw new Error('Choose a vault passphrase with at least 12 characters.');
    const salt = randomBytes(16);
    const wrappingKey = await derivePassphraseKey(passphrase, salt);
    const raw = randomBytes(32);
    const recoveryRaw = randomBytes(32);
    const recoveryKey = await crypto.subtle.importKey('raw', recoveryRaw, {name:'AES-GCM'}, false, ['encrypt','decrypt']);
    const wrapped = await wrapKeyBytes(raw, wrappingKey, 'ATS-CV-VAULT-KEY:v1:passphrase');
    const recoveryWrapped = await wrapKeyBytes(raw, recoveryKey, 'ATS-CV-VAULT-KEY:v1:recovery');
    const master = await crypto.subtle.importKey('raw', raw, {name:'AES-GCM'}, false, ['encrypt','decrypt']);
    const candidate = {
      metadata:{
        version:1,
        kdf:'PBKDF2-SHA-256',
        iterations:ITERATIONS,
        salt:toBase64(salt),
        wrap_nonce:wrapped.nonce,
        wrapped_key:wrapped.ciphertext,
        recovery_nonce:recoveryWrapped.nonce,
        recovery_wrapped_key:recoveryWrapped.ciphertext
      },
      recoveryKey:toBase64(recoveryRaw),
      rawMaster:raw,
      master
    };
    recoveryRaw.fill(0);
    return candidate;
  }

  function activate(nextMetadata, raw, key) {
    lock();
    metadata = nextMetadata;
    rawMasterKey = new Uint8Array(raw);
    masterKey = key;
  }

  function lock() {
    if (rawMasterKey) rawMasterKey.fill(0);
    rawMasterKey = null;
    masterKey = null;
  }

  async function decryptWrappedMaster(wrappingKey, nonceText, ciphertextText, context) {
    const raw = await crypto.subtle.decrypt(
      {name:'AES-GCM', iv:fromBase64(nonceText), additionalData:aad(context), tagLength:128},
      wrappingKey,
      fromBase64(ciphertextText)
    );
    const bytes = new Uint8Array(raw);
    if (bytes.length !== 32) throw new Error('The vault key has an invalid size.');
    const key = await crypto.subtle.importKey('raw', bytes, {name:'AES-GCM'}, false, ['encrypt','decrypt']);
    return {raw:bytes,key};
  }

  async function unlockWithPassphrase(savedMetadata, passphrase) {
    if (!window.isSecureContext || !crypto?.subtle) throw new Error('The encrypted vault requires HTTPS or localhost and a browser with Web Crypto support.');
    if (!savedMetadata) throw new Error('Set up the vault first.');
    const wrappingKey = await derivePassphraseKey(passphrase, fromBase64(savedMetadata.salt));
    const opened = await decryptWrappedMaster(wrappingKey, savedMetadata.wrap_nonce, savedMetadata.wrapped_key, 'ATS-CV-VAULT-KEY:v1:passphrase');
    activate(savedMetadata, opened.raw, opened.key);
    opened.raw.fill(0);
    return true;
  }

  async function unlockWithRecovery(savedMetadata, recoveryText) {
    if (!savedMetadata) throw new Error('Set up the vault first.');
    const rawRecovery = fromBase64(recoveryText.trim());
    if (rawRecovery.length !== 32) throw new Error('The recovery key must be copied exactly from your recovery file.');
    const recoveryKey = await crypto.subtle.importKey('raw', rawRecovery, {name:'AES-GCM'}, false, ['encrypt','decrypt']);
    const opened = await decryptWrappedMaster(recoveryKey, savedMetadata.recovery_nonce, savedMetadata.recovery_wrapped_key, 'ATS-CV-VAULT-KEY:v1:recovery');
    activate(savedMetadata, opened.raw, opened.key);
    opened.raw.fill(0);
    rawRecovery.fill(0);
    return true;
  }

  async function encryptDraft(id, draftName, cv) {
    requireUnlocked();
    const rawDataKey = randomBytes(32);
    let clearPayload = null;
    try {
      const dataKey = await crypto.subtle.importKey('raw', rawDataKey, {name:'AES-GCM'}, false, ['encrypt','decrypt']);
      const dataNonce = randomBytes(12);
      clearPayload = encoder.encode(JSON.stringify({draft_name:draftName, cv}));
      const cipher = await crypto.subtle.encrypt(
        {name:'AES-GCM', iv:dataNonce, additionalData:aad(`ATS-CV-DRAFT:v1:${id}`), tagLength:128},
        dataKey,
        clearPayload
      );
      const wrapped = await wrapKeyBytes(rawDataKey, masterKey, `ATS-CV-DATA-KEY:v1:${id}`);
      const nameNonce = randomBytes(12);
      const encryptedName = await crypto.subtle.encrypt(
        {name:'AES-GCM', iv:nameNonce, additionalData:aad(`ATS-CV-NAME:v1:${id}`), tagLength:128},
        masterKey,
        encoder.encode(draftName)
      );
      return {
        payload:{version:1, algorithm:'AES-GCM-256', data_nonce:toBase64(dataNonce), wrap_nonce:wrapped.nonce, wrapped_key:wrapped.ciphertext, ciphertext:toBase64(new Uint8Array(cipher))},
        name:{version:1, algorithm:'AES-GCM-256', nonce:toBase64(nameNonce), ciphertext:toBase64(new Uint8Array(encryptedName))}
      };
    } finally {
      rawDataKey.fill(0);
      if (clearPayload) clearPayload.fill(0);
    }
  }

  async function decryptName(id, envelope) {
    requireUnlocked();
    if (!envelope) throw new Error('Encrypted draft name is missing.');
    const clear = await crypto.subtle.decrypt(
      {name:'AES-GCM', iv:fromBase64(envelope.nonce), additionalData:aad(`ATS-CV-NAME:v1:${id}`), tagLength:128},
      masterKey,
      fromBase64(envelope.ciphertext)
    );
    try { return decoder.decode(clear); }
    finally { new Uint8Array(clear).fill(0); }
  }

  async function decryptDraft(id, envelope) {
    requireUnlocked();
    if (!envelope || envelope.version !== 1 || envelope.algorithm !== 'AES-GCM-256') throw new Error('Unsupported encrypted draft format.');
    let rawKey=null,clear=null,record;
    try {
      rawKey = await crypto.subtle.decrypt(
        {name:'AES-GCM', iv:fromBase64(envelope.wrap_nonce), additionalData:aad(`ATS-CV-DATA-KEY:v1:${id}`), tagLength:128},
        masterKey,
        fromBase64(envelope.wrapped_key)
      );
      const dataKey = await crypto.subtle.importKey('raw', rawKey, {name:'AES-GCM'}, false, ['decrypt']);
      clear = await crypto.subtle.decrypt(
        {name:'AES-GCM', iv:fromBase64(envelope.data_nonce), additionalData:aad(`ATS-CV-DRAFT:v1:${id}`), tagLength:128},
        dataKey,
        fromBase64(envelope.ciphertext)
      );
      record = JSON.parse(decoder.decode(clear));
    } finally {
      if(rawKey)new Uint8Array(rawKey).fill(0);
      if(clear)new Uint8Array(clear).fill(0);
    }
    if (!record || typeof record.draft_name !== 'string' || !record.cv || typeof record.cv !== 'object') throw new Error('Encrypted draft contents are invalid.');
    return record;
  }

  async function changePassphrase(passphrase) {
    requireUnlocked();
    if (passphrase.length < 12) throw new Error('Choose a vault passphrase with at least 12 characters.');
    const salt = randomBytes(16);
    const wrappingKey = await derivePassphraseKey(passphrase, salt);
    const wrapped = await wrapKeyBytes(rawMasterKey, wrappingKey, 'ATS-CV-VAULT-KEY:v1:passphrase');
    const replacement = {...metadata, salt:toBase64(salt), wrap_nonce:wrapped.nonce, wrapped_key:wrapped.ciphertext};
    const response = await request('vault_update', {expected_metadata:JSON.stringify(metadata), metadata:JSON.stringify(replacement)});
    metadata = response.metadata;
  }

  function requireUnlocked() {
    if (!masterKey || !rawMasterKey) throw new Error('Unlock the encrypted vault first.');
  }

  async function request(action, values = {}) {
    const body = new FormData();
    body.append('action', action);
    body.append('csrf_token', window.ATS_CSRF);
    for (const [key, value] of Object.entries(values)) body.append(key, value);
    const response = await fetch(window.ATS_BASE_URL + 'index.php', {method:'POST', body, cache:'no-store'});
    const result = await response.json();
    if (!response.ok || !result.ok) throw new Error(result.error || 'Vault request failed.');
    return result;
  }

  window.ATSCvVault = {
    get unlocked() { return !!masterKey; },
    get metadata() { return metadata; },
    createCandidate,
    unlockWithPassphrase,
    unlockWithRecovery,
    activate,
    lock,
    encryptDraft,
    decryptDraft,
    decryptName,
    changePassphrase,
    request,
    toBase64
  };
})();
