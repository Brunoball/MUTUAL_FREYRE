import { apiGet } from "../../shared/httpClient";

export const getAuditoriaStructure = () => apiGet("auditoria");
