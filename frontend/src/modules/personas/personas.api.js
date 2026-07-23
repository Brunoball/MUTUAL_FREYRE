import { apiGet, apiPatch, apiPost, apiPut } from "../../shared/httpClient";

export const getPersonas = (params, options) => apiGet("personas", params, options);
export const getPersonasCatalogos = (options) => apiGet("personas/catalogos", { _ts: Date.now() }, options);
export const getPersonaDetalle = (id, options) => apiGet("personas/detalle", { id }, options);
export const crearPersona = (payload) => apiPost("personas", payload);
export const actualizarPersona = (id, payload) => apiPut("personas", payload, { query: { id } });
export const cambiarEstadoPersona = (id, activo, motivo = "") =>
  apiPatch("personas/estado", { activo, motivo }, { query: { id } });

export const getPersonasStructure = getPersonas;
