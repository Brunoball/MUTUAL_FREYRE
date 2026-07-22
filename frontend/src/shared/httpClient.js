import { API_BASE_URL } from "../config/env";
import { clearStoredSession, getCsrfToken } from "./session";

function buildUrl(path, query) {
  const normalizedPath = String(path || "").replace(/^\/+/, "");
  const url = new URL(`${API_BASE_URL}/${normalizedPath}`, window.location.origin);
  Object.entries(query || {}).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== "") {
      url.searchParams.set(key, String(value));
    }
  });
  return url.toString();
}

async function request(path, options = {}) {
  const method = String(options.method || "GET").toUpperCase();
  const hasBody = options.body !== undefined && options.body !== null;
  const response = await fetch(buildUrl(path, options.query), {
    method,
    credentials: "include",
    signal: options.signal,
    headers: {
      Accept: "application/json",
      ...(hasBody ? { "Content-Type": "application/json" } : {}),
      ...(method !== "GET" && method !== "HEAD" && getCsrfToken()
        ? { "X-CSRF-Token": getCsrfToken() }
        : {}),
      ...(options.headers || {}),
    },
    ...(hasBody ? { body: JSON.stringify(options.body) } : {}),
  });

  const text = await response.text();
  let payload;
  try {
    payload = text ? JSON.parse(text) : {};
  } catch {
    const error = new Error("El servidor devolvió una respuesta inválida.");
    error.status = response.status;
    throw error;
  }

  if (response.status === 401) {
    clearStoredSession();
    window.dispatchEvent(new CustomEvent("mutual:unauthorized"));
  }

  if (!response.ok || payload?.ok === false) {
    const error = new Error(payload?.error?.message || payload?.mensaje || "No se pudo completar la operación.");
    error.status = response.status;
    error.code = payload?.error?.code || payload?.codigo;
    error.fields = payload?.error?.fields || {};
    error.correlationId = payload?.error?.correlation_id;
    throw error;
  }

  return payload?.data ?? payload;
}

export const apiGet = (path, query, options = {}) => request(path, { ...options, query, method: "GET" });
export const apiPost = (path, body, options = {}) => request(path, { ...options, body, method: "POST" });
export const apiPut = (path, body, options = {}) => request(path, { ...options, body, method: "PUT" });
export const apiPatch = (path, body, options = {}) => request(path, { ...options, body, method: "PATCH" });
export const apiDelete = (path, body, options = {}) => request(path, { ...options, body, method: "DELETE" });
