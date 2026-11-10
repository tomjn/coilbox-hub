// The preview data the map page hands the terrain, built by
// App\Domain\Maps\MapPage::preview(). The shape of `lib/maps/preview.ts`.

import type { HeightRange } from "./heights";

/** RGB in 0 to 1, as mapinfo.lua writes them. Null means the map said nothing. */
export interface PreviewAppearance {
  water: [number, number, number] | null;
  waterAlpha: number | null;
  sunDirection: [number, number, number] | null;
  sunColour: [number, number, number] | null;
}

/** A marker's place in the map's own coordinates. The height is read off the ground. */
export interface PreviewPoint {
  x: number;
  z: number;
}

export interface PreviewPoints {
  start: PreviewPoint[];
  metal: PreviewPoint[];
  geo: PreviewPoint[];
}

export interface MapPreview {
  heightUrl: string;
  /** What the overlay's darkest and brightest samples mean. The overlay row's pair, never the map's. */
  range: HeightRange;
  textureUrl: string;
  widthElmos: number;
  heightElmos: number;
  voidWater: boolean;
  appearance: PreviewAppearance;
  points: PreviewPoints;
}
