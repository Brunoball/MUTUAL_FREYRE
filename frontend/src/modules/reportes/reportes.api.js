import { apiGet } from "../../shared/httpClient";

export const getReportesStructure = () => apiGet("reportes");
