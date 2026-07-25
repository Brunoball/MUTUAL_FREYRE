import { apiGet } from "../../_shared/httpClient";

export const getAuditoria = (filters = {}, options = {}) =>
  apiGet("auditoria", filters, options);

export const getAuditoriaDetalle = (id, options = {}) =>
  apiGet("auditoria/detalle", { id }, options);

export const verificarIntegridadAuditoria = (options = {}) =>
  apiGet("auditoria/integridad", {}, options);
