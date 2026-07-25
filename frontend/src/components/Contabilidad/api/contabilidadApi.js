import { apiGet } from "../../Global/api/httpClient";

export const getContabilidadStructure = () => apiGet("contabilidad");
