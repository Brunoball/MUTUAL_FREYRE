import { apiGet } from "../../shared/httpClient";

export const getAyudasStructure = () => apiGet("ayudas");
