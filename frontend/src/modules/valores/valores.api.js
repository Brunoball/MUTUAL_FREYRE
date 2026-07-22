import { apiGet } from "../../shared/httpClient";

export const getValoresStructure = () => apiGet("valores");
