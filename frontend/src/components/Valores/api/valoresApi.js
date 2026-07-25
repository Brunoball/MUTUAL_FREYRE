import { apiGet } from "../../Global/api/httpClient";

export const getValoresStructure = () => apiGet("valores");
