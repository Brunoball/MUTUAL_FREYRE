import { apiGet } from "../../_shared/httpClient";

export const getContabilidadStructure = () => apiGet("contabilidad");
