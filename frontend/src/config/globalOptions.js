const freezeOptions = (items) => Object.freeze(items.map((item) => Object.freeze(item)));

export const toUpperText = (value) => String(value ?? "").toLocaleUpperCase("es-AR");

export const uppercaseDeep = (value) => {
  if (Array.isArray(value)) return value.map(uppercaseDeep);
  if (value && typeof value === "object") {
    return Object.fromEntries(Object.entries(value).map(([key, item]) => [key, uppercaseDeep(item)]));
  }
  return typeof value === "string" ? toUpperText(value) : value;
};

export const GLOBAL_OPTIONS = Object.freeze({
  personTypes: freezeOptions([
    { value: "FISICA", label: "PERSONA FÍSICA" },
    { value: "JURIDICA", label: "PERSONA JURÍDICA" },
  ]),
  genders: freezeOptions([
    { value: "NO_INFORMA", label: "NO INFORMA" },
    { value: "FEMENINO", label: "FEMENINO" },
    { value: "MASCULINO", label: "MASCULINO" },
    { value: "NO_BINARIO", label: "NO BINARIO" },
    { value: "OTRO", label: "OTRO" },
  ]),
  maritalStatuses: freezeOptions([
    { value: "NO_INFORMA", label: "NO INFORMA" },
    { value: "SOLTERO", label: "SOLTERO/A" },
    { value: "CASADO", label: "CASADO/A" },
    { value: "DIVORCIADO", label: "DIVORCIADO/A" },
    { value: "VIUDO", label: "VIUDO/A" },
    { value: "UNION_CONVIVENCIAL", label: "UNIÓN CONVIVENCIAL" },
  ]),
  associateStatuses: freezeOptions([
    { value: "PENDIENTE", label: "PENDIENTE" },
    { value: "ACTIVO", label: "ACTIVO" },
    { value: "SUSPENDIDO", label: "SUSPENDIDO" },
    { value: "INACTIVO", label: "INACTIVO" },
    { value: "BAJA", label: "BAJA" },
    { value: "FALLECIDO", label: "FALLECIDO" },
    { value: "RECHAZADO", label: "RECHAZADO" },
  ]),
  authorizedOperations: freezeOptions([
    { value: "CONSULTAR", label: "CONSULTAR INFORMACIÓN" },
    { value: "DEPOSITAR", label: "REALIZAR DEPÓSITOS" },
    { value: "RETIRAR", label: "REALIZAR RETIROS" },
    { value: "FIRMAR", label: "FIRMAR DOCUMENTACIÓN" },
    { value: "RETIRAR_DOCUMENTACION", label: "RETIRAR DOCUMENTACIÓN" },
  ]),
  statusFilters: freezeOptions([
    { value: "ACTIVAS", label: "ACTIVAS" },
    { value: "BAJAS", label: "BAJAS" },
    { value: "TODAS", label: "TODAS" },
  ]),
  membershipFilters: freezeOptions([
    { value: "SI", label: "ASOCIADOS" },
    { value: "NO", label: "NO ASOCIADOS" },
  ]),
});

export const PERSONAS_SECTIONS = Object.freeze({
  general: Object.freeze({ key: "general", label: "DATOS COMUNES" }),
  physical: Object.freeze({ key: "specific", label: "PERSONA FÍSICA" }),
  legal: Object.freeze({ key: "specific", label: "PERSONA JURÍDICA" }),
  financial: Object.freeze({ key: "financial", label: "DATOS FINANCIEROS" }),
  associate: Object.freeze({ key: "associate", label: "ASOCIACIÓN" }),
  authorized: Object.freeze({ key: "authorized", label: "AUTORIZADOS" }),
  beneficiaries: Object.freeze({ key: "beneficiaries", label: "BENEFICIARIOS FINALES" }),
});

export const catalogToOptions = (items = []) =>
  items.map((item) => ({ value: String(item.id), label: toUpperText(item.nombre) }));

export const buildPersonSections = (type, counts = {}) => [
  PERSONAS_SECTIONS.general,
  type === "JURIDICA" ? PERSONAS_SECTIONS.legal : PERSONAS_SECTIONS.physical,
  PERSONAS_SECTIONS.financial,
  PERSONAS_SECTIONS.associate,
  { ...PERSONAS_SECTIONS.authorized, count: counts.authorized ?? 0 },
  ...(type === "JURIDICA"
    ? [{ ...PERSONAS_SECTIONS.beneficiaries, count: counts.beneficiaries ?? 0 }]
    : []),
];
