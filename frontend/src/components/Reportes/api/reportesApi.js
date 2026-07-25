import { apiGet } from "../../_shared/httpClient";

export const getReportesStructure = () => apiGet("reportes");
