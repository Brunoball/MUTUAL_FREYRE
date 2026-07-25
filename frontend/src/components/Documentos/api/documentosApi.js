import { apiGet } from "../../Global/api/httpClient";

export const getDocumentosStructure = () => apiGet("documentos");
