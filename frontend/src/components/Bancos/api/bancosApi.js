import { apiGet } from "../../Global/api/httpClient";

export const getBancosStructure = () => apiGet("bancos");
