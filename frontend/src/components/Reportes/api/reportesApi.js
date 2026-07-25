import { apiGet } from "../../Global/api/httpClient";

export const getReportesStructure = () => apiGet("reportes");
