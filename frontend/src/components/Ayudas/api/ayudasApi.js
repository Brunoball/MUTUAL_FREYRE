import { apiGet, apiPatch, apiPost } from "../../Global/api/httpClient";

export const getAyudas = (params) => apiGet("ayudas", params);
export const getAyudasCatalogos = (fecha) =>
  apiGet("ayudas/catalogos", { fecha });
export const getAyudaDetalle = (id) => apiGet("ayudas/detalle", { id });
export const getAyudasParametros = () => apiGet("ayudas/parametros");
export const consultarCotizacionBancoNacion = () =>
  apiGet("ayudas/parametros/cotizacion-bna");
export const simularAyuda = (payload) => apiPost("ayudas/simular", payload);
export const liquidarAyuda = (payload) => apiPost("ayudas", payload);
export const renovarAyuda = (id, payload) =>
  apiPost("ayudas/renovar", payload, { query: { id } });
export const anularAyuda = (id, payload) =>
  apiPatch("ayudas/anular", payload, { query: { id } });
export const guardarTasaAyuda = (payload) =>
  apiPost("ayudas/parametros/tasas", payload);
export const guardarCotizacionDolar = (payload) =>
  apiPost("ayudas/parametros/cotizacion-dolar", payload);

const INFORMES_RIESGO = "ayudas/informes-riesgo";

export const getInformesRiesgo = (params = {}) =>
  apiGet(INFORMES_RIESGO, params);
export const getInformeRiesgoDetalle = (id) =>
  apiGet(`${INFORMES_RIESGO}/detalle`, { id });
export const generarInformeRiesgo = (payload) =>
  apiPost(`${INFORMES_RIESGO}/generar`, payload);
export const guardarEvaluacionUif = (id, payload) =>
  apiPost(`${INFORMES_RIESGO}/evaluacion-uif`, payload, { query: { id } });
export const guardarDictamenRiesgo = (id, payload) =>
  apiPost(`${INFORMES_RIESGO}/dictamen`, payload, { query: { id } });
export const refrescarFuentesBcra = (id) =>
  apiPost(`${INFORMES_RIESGO}/refrescar-bcra`, {}, { query: { id } });
export const refrescarFuenteRepet = (id) =>
  apiPost(`${INFORMES_RIESGO}/refrescar-repet`, {}, { query: { id } });

// Compatibilidad con importaciones previas del módulo inicial.
export const getAyudasStructure = getAyudas;
