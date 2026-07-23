import { apiGet, apiPatch, apiPost } from "../../shared/httpClient";

export const getAyudas = (params) => apiGet("ayudas", params);
export const getAyudasCatalogos = (fecha) => apiGet("ayudas/catalogos", { fecha });
export const getAyudaDetalle = (id) => apiGet("ayudas/detalle", { id });
export const getAyudasParametros = () => apiGet("ayudas/parametros");
export const consultarCotizacionBancoNacion = () =>
  apiGet("ayudas/parametros/cotizacion-bna");
export const simularAyuda = (payload) => apiPost("ayudas/simular", payload);
export const liquidarAyuda = (payload) => apiPost("ayudas", payload);
export const renovarAyuda = (id, payload) => apiPost("ayudas/renovar", payload, { query: { id } });
export const anularAyuda = (id, payload) => apiPatch("ayudas/anular", payload, { query: { id } });
export const guardarTasaAyuda = (payload) => apiPost("ayudas/parametros/tasas", payload);
export const guardarCotizacionDolar = (payload) =>
  apiPost("ayudas/parametros/cotizacion-dolar", payload);

// Compatibilidad con importaciones previas del módulo inicial.
export const getAyudasStructure = getAyudas;
